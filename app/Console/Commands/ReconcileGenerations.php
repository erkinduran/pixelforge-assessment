<?php

namespace App\Console\Commands;

use App\Generations\ApplyPixelforgeGeneration;
use App\Generations\SubmitGeneration;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The fallback for webhooks that never came and jobs that never finished.
 * It never writes `failed` itself: a GET error, a timeout or an unknown answer is logged and the
 * row is left alone. Only a Pixelforge object, passed through the one apply() function, changes a row.
 *
 * `updated_at` doubles as "last looked at": every row is touched when we look at it, so a row
 * that cannot be resolved goes to the back of the line and is not asked again for a few minutes.
 */
class ReconcileGenerations extends Command
{
    protected $signature = 'pixelforge:reconcile';

    protected $description = 'Ask Pixelforge about generations that are stuck, and re-send ones it never received';

    /** All Pixelforge requests (GET, list, and each re-dispatch, which ends in one POST) per run. The 60/min limit is shared. */
    public const REQUEST_BUDGET = 20;

    /** A row with a Pixelforge id is asked about after this long without news. */
    public const POLL_AFTER_MINUTES = 2;

    /** A row WITHOUT an id is re-sent after this long without news (a live job also holds the unique lock). */
    public const RESUBMIT_AFTER_MINUTES = 5;

    /** An apply_failed row older than this is no longer re-sent (it would fail the same way again). */
    public const APPLY_FAILED_GIVE_UP_HOURS = 24;

    /** Pages of GET /generations (100 each) searched per run for rows after a 409. */
    public const LIST_PAGES = 3;

    /** last_submit_error values meaning "a generation already exists under this key": never re-POST these. */
    public const CONFLICT_MARKERS = ['idempotency_key_reused', 'http_409'];

    private int $budget = self::REQUEST_BUDGET;

    public function handle(PixelforgeClient $client, ApplyPixelforgeGeneration $apply): int
    {
        $this->budget = self::REQUEST_BUDGET;

        // Oldest news first inside each step; the steps run in this order of importance.
        $this->pollKnownGenerations($client, $apply);
        $this->searchConflictedGenerations($client, $apply);
        $this->resubmitUnknownGenerations();

        return self::SUCCESS;
    }

    /** Rows Pixelforge has an id for: ask it (GET /generations/{id}) and apply the answer. */
    private function pollKnownGenerations(PixelforgeClient $client, ApplyPixelforgeGeneration $apply): void
    {
        $rows = Generation::query()
            ->whereNotNull('pixelforge_id')
            ->whereNotIn('status', [Generation::SUCCEEDED, Generation::FAILED])
            ->where('updated_at', '<=', now()->subMinutes(self::POLL_AFTER_MINUTES))
            ->orderBy('updated_at')
            ->limit($this->budget)
            ->get();

        foreach ($rows as $row) {
            $this->budget--;
            $row->touch();

            try {
                $apply->apply($client->getGeneration((string) $row->pixelforge_id));
            } catch (PixelforgeException $e) {
                // Any error, including a timeout: not our place to decide the outcome.
                Log::warning("Reconcile: could not read generation {$row->id}: {$e->getMessage()}");
            } catch (Throwable $e) {
                Log::error("Reconcile: could not apply the answer for generation {$row->id}: {$e->getMessage()}");
            }
        }
    }

    /**
     * Rows without an id whose last answer was 409: a generation already exists under their key,
     * so they are never re-POSTed. Page through GET /generations (newest first) and match
     * `metadata.local_id` on each item. Not found within a few pages: leave the row, its webhook may still come.
     */
    private function searchConflictedGenerations(PixelforgeClient $client, ApplyPixelforgeGeneration $apply): void
    {
        $wanted = Generation::query()
            ->whereNull('pixelforge_id')
            ->where('status', Generation::QUEUED)
            ->whereIn('last_submit_error', self::CONFLICT_MARKERS)
            ->where('updated_at', '<=', now()->subMinutes(self::POLL_AFTER_MINUTES))
            ->orderBy('updated_at')
            ->get()
            ->keyBy('id');

        $cursor = null;
        $listFailed = false;

        for ($page = 0; $page < self::LIST_PAGES && $this->budget > 0 && $wanted->isNotEmpty(); $page++) {
            $this->budget--;

            try {
                $list = $client->listGenerations($cursor, 100);
            } catch (PixelforgeException $e) {
                Log::warning("Reconcile: could not list generations: {$e->getMessage()}");
                $listFailed = true;
                break;
            }

            foreach ($list['data'] ?? [] as $item) {
                $localId = is_array($item) ? ($item['metadata']['local_id'] ?? null) : null;

                if (! is_string($localId) || ! $wanted->has($localId)) {
                    continue;
                }

                $row = $wanted->get($localId);
                $wanted->forget($localId);

                try {
                    $apply->apply($item);
                } catch (Throwable $e) {
                    // Found, but its answer cannot be stored: go to the back of the line, so the list is not searched for it every minute.
                    $row->touch();
                    Log::error("Reconcile: could not apply the listed object for generation {$localId}: {$e->getMessage()}");
                }
            }

            $next = $list['next_cursor'] ?? null;

            if (empty($list['has_more']) || ! is_string($next)) {
                break;
            }

            $cursor = $next;
        }

        // A failed request says nothing about the rows: they stay as they are and are tried again next minute.
        if (! $listFailed) {
            foreach ($wanted as $row) {
                $row->touch();
                Log::info("Reconcile: generation {$row->id} is not in the newest pages of the list yet (waiting for its webhook).");
            }
        }
    }

    /**
     * Rows still queued with no id (and no 409): the job gave up or was lost. Dispatch it again:
     * same Idempotency-Key and the same body (both come from the row), so a replay is not billed.
     * SubmitGeneration is unique per generation, so while a job is alive this dispatch does nothing.
     */
    private function resubmitUnknownGenerations(): void
    {
        // A row whose POST answer could not be stored (apply_failed) fails the same way every
        // time. It is re-sent for a day, in case a bug is fixed meanwhile, then left alone.
        $giveUpBefore = now()->subHours(self::APPLY_FAILED_GIVE_UP_HOURS);

        $skipped = Generation::query()
            ->whereNull('pixelforge_id')
            ->where('status', Generation::QUEUED)
            ->where('last_submit_error', 'apply_failed')
            ->where('created_at', '<', $giveUpBefore)
            ->count();

        if ($skipped > 0) {
            Log::info("Reconcile: skipped {$skipped} generation(s) with apply_failed older than ".self::APPLY_FAILED_GIVE_UP_HOURS.' hours.');
        }

        $rows = Generation::query()
            ->whereNull('pixelforge_id')
            ->where('status', Generation::QUEUED)
            ->where(fn ($query) => $query->whereNull('last_submit_error')->orWhereNotIn('last_submit_error', self::CONFLICT_MARKERS))
            ->where(fn ($query) => $query->whereNull('last_submit_error')->orWhere('last_submit_error', '!=', 'apply_failed')->orWhere('created_at', '>=', $giveUpBefore))
            ->where('updated_at', '<=', now()->subMinutes(self::RESUBMIT_AFTER_MINUTES))
            ->orderBy('updated_at')
            ->limit(max($this->budget, 0))
            ->get();

        foreach ($rows as $row) {
            $this->budget--;
            $row->touch();

            SubmitGeneration::dispatch($row->id);
        }
    }
}
