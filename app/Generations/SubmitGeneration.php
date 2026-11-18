<?php

namespace App\Generations;

use App\Models\Generation;
use App\Pixelforge\Decision;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one local generation to Pixelforge (POST /generations).
 *
 * Every attempt sends the same body and the same Idempotency-Key, both built from the row, so a
 * retry can never create a second generation. What to do after a failure is decided by
 * SubmitPolicy; this class only carries it out.
 *
 * A row that is left queued with no pixelforge_id is picked up by the fallback poller (later
 * ticket), which dispatches this job again: same key, same body. A row that has a pixelforge_id
 * is polled with GET /generations/{id} instead. There is no "stuck" status.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    // No $tries on purpose. The queue counts every release() as a try, and a concurrency wait is
    // a release, so a count limit would be used up by waiting. With retryUntil() defined the
    // worker ignores attempt counts (Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts), and an
    // explicit $tries = 0 would even override the worker's --tries. The limits are our own:
    // submit_attempts on the row for real failures, and retryUntil() for the total time.

    private readonly CarbonInterface $dispatchedAt;

    public function __construct(public readonly string $generationId)
    {
        $this->dispatchedAt = now();
    }

    /** The queue stops this dispatch after the wait budget plus a margin for the attempts. */
    public function retryUntil(): CarbonInterface
    {
        return $this->dispatchedAt->copy()->addSeconds(SubmitPolicy::WAIT_BUDGET_SECONDS + 300);
    }

    /**
     * Seconds to wait before the queue retries after an unexpected exception (a bug, a database
     * error). Expected Pixelforge errors never get here: handle() catches them.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(
        PixelforgeClient $client,
        ApplyPixelforgeGeneration $apply,
        SubmitPolicy $policy,
        RecordSubmitFailure $failures,
    ): void {
        $generation = Generation::query()->find($this->generationId);

        // Nothing to send: the row is gone, finished, or Pixelforge already knows it.
        if ($generation === null || $generation->isFinal() || $generation->pixelforge_id !== null) {
            return;
        }

        try {
            $created = $client->createGeneration($this->body($generation), $generation->id);
        } catch (PixelforgeException $e) {
            $this->afterFailure($e, $generation, $policy, $failures);

            return;
        }

        try {
            // The POST response (also a replay, which may already be succeeded) goes through the
            // same function as webhooks and the poller.
            $apply->apply($created['generation']);
        } catch (Throwable $e) {
            // Pixelforge created the generation but we could not store its answer. Never write
            // `failed` for this row: the poller or a webhook will finish it.
            $this->markUnknownOutcome($generation);
            Generation::query()->whereKey($generation->id)->update(['last_submit_error' => 'apply_failed']);

            throw $e;
        }
    }

    /**
     * Only called when the queue gave up on this job (retryUntil passed, or too many unexpected
     * exceptions). It never changes the row: the job may have lost a race with a webhook,
     * and Pixelforge may be billing the generation. The poller picks the row up.
     */
    public function failed(?Throwable $e): void
    {
        Log::warning("SubmitGeneration for {$this->generationId} stopped by the queue: ".($e?->getMessage() ?? 'unknown reason'));
    }

    /**
     * Built ONLY from the row, so it is byte-for-byte the same on every attempt (Pixelforge
     * answers 409 if the body changes under the same key). Our column is reference_image_urls;
     * the API calls it reference_images. An empty list is left out.
     *
     * @return array<string, mixed>
     */
    private function body(Generation $generation): array
    {
        $body = [
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
        ];

        if ($generation->reference_image_urls !== []) {
            $body['reference_images'] = $generation->reference_image_urls;
        }

        $body['metadata'] = ['local_id' => $generation->id];

        return $body;
    }

    private function afterFailure(PixelforgeException $e, Generation $generation, SubmitPolicy $policy, RecordSubmitFailure $failures): void
    {
        $unknownOutcomeAt = $generation->unknown_outcome_at;

        // Set before deciding, once, never cleared.
        if ($e->isUnknownOutcome() && $unknownOutcomeAt === null) {
            $unknownOutcomeAt = $this->markUnknownOutcome($generation);
        }

        $attempts = $generation->submit_attempts;

        // The poller reads this to tell a 409 row (never re-POST) from a timeout row (safe to re-POST).
        Generation::query()->whereKey($generation->id)->update([
            'last_submit_error' => $e->errorCode ?? ($e->status ? "http_{$e->status}" : 'no_response'),
        ]);

        // Only real failures count. Concurrency waits and definite rejections do not.
        if ($e->decision() === Decision::Retry) {
            Generation::query()->whereKey($generation->id)->increment('submit_attempts');
        }

        $action = $policy->next($e, $attempts, $unknownOutcomeAt, $this->dispatchedAt, now());

        switch ($action->step) {
            case SubmitStep::Release:
                // Never wait longer than this job lives: a 3600 s Retry-After would outlive retryUntil().
                $timeLeft = $this->retryUntil()->getTimestamp() - now()->getTimestamp();

                if ($timeLeft <= 0) {
                    Log::warning("SubmitGeneration for {$generation->id} ran out of time and left it queued: {$e->getMessage()}");
                    break;
                }

                $this->release(min($action->seconds, $timeLeft));
                break;

            case SubmitStep::Fail:
                // The user sees our own message; the raw provider text goes to the log only.
                Log::warning("SubmitGeneration for {$generation->id} failed: {$e->errorCode} (HTTP {$e->status}): {$e->getMessage()}");
                $failures->record($generation->id, (string) $action->failureCode, (string) $action->failureMessage);
                break;

            case SubmitStep::GiveUp:
                Log::warning("SubmitGeneration for {$generation->id} gave up and left it queued: {$e->getMessage()}");
                break;
        }
    }

    private function markUnknownOutcome(Generation $generation): CarbonInterface
    {
        $now = now();

        Generation::query()->whereKey($generation->id)->whereNull('unknown_outcome_at')->update(['unknown_outcome_at' => $now]);

        return $now;
    }
}
