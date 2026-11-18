<?php

namespace Tests\Feature\Generations;

use App\Generations\RecordSubmitFailure;
use App\Generations\SubmitGeneration;
use App\Models\Charge;
use App\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SubmitGenerationTest extends TestCase
{
    use PixelforgeObjects;
    use RefreshDatabase;

    /** Runs one attempt and returns the fake queue job, which says whether it was released and for how long. */
    private function attempt(SubmitGeneration $job): FakeJob
    {
        $job->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        /** @var FakeJob $fake */
        $fake = $job->job;

        return $fake;
    }

    /** @return array<string, mixed> */
    private function error(string $type, string $code): array
    {
        return ['error' => ['type' => $type, 'code' => $code, 'message' => "test message for {$code}"]];
    }

    // A fake response is a [body, status, headers] triple: Http::response(...$triple) or $sequence->push(...$triple).

    /** @return array{array<string, mixed>, int, array<string, string>} */
    private function concurrency(): array
    {
        return [$this->error('rate_limit_error', 'concurrency_limit_exceeded'), 429, ['Retry-After' => '5']];
    }

    /** @return array{array<string, mixed>, int, array<string, string>} */
    private function overloaded(): array
    {
        return [$this->error('api_error', 'overloaded'), 503, ['Retry-After' => '2']];
    }

    private function timeout(): ConnectionException
    {
        return new ConnectionException('cURL error 28: timed out');
    }

    private function fakeSequence(mixed ...$responses): void
    {
        $sequence = Http::sequence();
        foreach ($responses as $response) {
            $response instanceof ConnectionException ? $sequence->pushFailedConnection($response->getMessage()) : $sequence->push(...$response);
        }
        Http::fake(['*/generations' => $sequence]);
    }

    /** @return array<int, Request> */
    private function sentRequests(): array
    {
        return Http::recorded()->map(fn (array $pair) => $pair[0])->values()->all();
    }

    // --- success ---

    public function test_202_stores_id_and_rates_and_sends_the_exact_request(): void
    {
        $generation = Generation::factory()->create();
        Http::fake(['*/generations' => Http::response($this->queuedObject($generation), 202)]);

        $fake = $this->attempt(new SubmitGeneration($generation->id));

        $row = $generation->fresh();
        $this->assertSame('queued', $row->status);
        $this->assertSame('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K', $row->pixelforge_id);
        $this->assertSame(5_000_000, $row->rate_text);
        $this->assertNull($row->unknown_outcome_at);
        $this->assertFalse($fake->isReleased());

        Http::assertSentCount(1);
        $request = $this->sentRequests()[0];
        $this->assertSame([$generation->id], $request->header('Idempotency-Key'));
        // Built only from the row; our reference_image_urls is the API's reference_images.
        $this->assertSame([
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/style.png'],
            'metadata' => ['local_id' => $generation->id],
        ], $request->data());
    }

    public function test_an_empty_reference_list_is_left_out_of_the_body(): void
    {
        $generation = Generation::factory()->create(['reference_image_urls' => []]);
        Http::fake(['*/generations' => Http::response($this->queuedObject($generation), 202)]);

        $this->attempt(new SubmitGeneration($generation->id));

        $this->assertArrayNotHasKey('reference_images', $this->sentRequests()[0]->data());
    }

    public function test_a_replay_that_is_already_succeeded_finishes_the_row_with_one_charge(): void
    {
        $generation = Generation::factory()->create();
        Http::fake(['*/generations' => Http::response($this->succeededObject($generation), 202, ['Idempotent-Replayed' => 'true'])]);

        $this->attempt(new SubmitGeneration($generation->id));

        $this->assertSame('succeeded', $generation->fresh()->status);
        $this->assertSame(1, Charge::count());

        // Dispatched again (poller, duplicate dispatch): the row is final, nothing is sent, still one charge.
        Http::fake();
        $this->attempt(new SubmitGeneration($generation->id));
        Http::assertNothingSent();
        $this->assertSame(1, Charge::count());
    }

    // --- nothing to send ---

    public function test_no_request_for_a_final_row_a_known_row_or_a_missing_row(): void
    {
        Http::fake();
        $succeeded = Generation::factory()->create(['status' => 'succeeded']);
        $failed = Generation::factory()->create(['status' => 'failed']);
        $known = Generation::factory()->create(['pixelforge_id' => 'gen_KNOWN']);

        foreach ([$succeeded->id, $failed->id, $known->id, '00000000-0000-4000-8000-000000000000'] as $id) {
            $this->attempt(new SubmitGeneration($id));
        }

        Http::assertNothingSent();
    }

    // --- the request is identical every time ---

    public function test_the_body_and_key_are_byte_for_byte_the_same_on_every_attempt(): void
    {
        $generation = Generation::factory()->create();
        $this->fakeSequence($this->timeout(), $this->concurrency(), $this->overloaded(), [$this->queuedObject($generation), 202]);

        $job = new SubmitGeneration($generation->id);
        for ($i = 0; $i < 4; $i++) {
            $this->attempt($job);
        }

        $requests = $this->sentRequests();
        $this->assertCount(4, $requests);
        $this->assertCount(1, array_unique(array_map(fn (Request $r) => $r->body(), $requests)));
        $this->assertCount(1, array_unique(array_map(fn (Request $r) => $r->header('Idempotency-Key')[0], $requests)));
        $this->assertSame($generation->id, $requests[0]->header('Idempotency-Key')[0]);
    }

    // --- waiting for a concurrency slot ---

    public function test_concurrency_waits_release_with_retry_after_and_do_not_use_the_retry_cap(): void
    {
        $generation = Generation::factory()->create();
        // 10 waits, then 4 real failures (one short of the cap of 5), then success.
        $this->fakeSequence(...[
            ...array_fill(0, 10, $this->concurrency()),
            ...array_fill(0, 4, $this->overloaded()),
            [$this->queuedObject($generation), 202],
        ]);

        $job = new SubmitGeneration($generation->id);
        $delays = [];
        for ($i = 0; $i < 15; $i++) {
            $fake = $this->attempt($job);
            $delays[] = $fake->isReleased() ? $fake->releaseDelay : null;
        }

        $this->assertSame([...array_fill(0, 10, 5), 2, 4, 8, 16, null], $delays);
        $row = $generation->fresh();
        $this->assertSame('queued', $row->status);
        $this->assertSame('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K', $row->pixelforge_id);
        $this->assertSame(4, $row->submit_attempts); // only the 4 overloaded ones
        $this->assertNull($row->unknown_outcome_at);
    }

    public function test_after_the_wait_budget_the_job_gives_up_and_the_row_stays_queued(): void
    {
        $generation = Generation::factory()->create();
        Http::fake(['*/generations' => Http::response(...$this->concurrency())]);

        $job = new SubmitGeneration($generation->id);
        $this->assertTrue($this->attempt($job)->isReleased());

        Carbon::setTestNow(now()->addMinutes(11));
        $fake = $this->attempt($job);

        $this->assertFalse($fake->isReleased());
        $row = $generation->fresh();
        $this->assertSame('queued', $row->status);
        $this->assertNull($row->failure_code);
        $this->assertNull($row->unknown_outcome_at); // a wait is a known rejection
        $this->assertSame(0, $row->submit_attempts);
    }

    // --- retries with a cap ---

    public function test_overloaded_up_to_the_cap_writes_a_readable_failure_and_no_charge(): void
    {
        $generation = Generation::factory()->create();
        Http::fake(['*/generations' => Http::response(...$this->overloaded())]);

        $job = new SubmitGeneration($generation->id);
        $delays = [];
        for ($i = 0; $i < 5; $i++) {
            $fake = $this->attempt($job);
            $delays[] = $fake->isReleased() ? $fake->releaseDelay : null;
        }

        $this->assertSame([2, 4, 8, 16, null], $delays);
        Http::assertSentCount(5);
        $row = $generation->fresh();
        $this->assertSame('failed', $row->status);
        $this->assertSame('overloaded', $row->failure_code);
        $this->assertStringContainsString('overloaded', $row->failure_message);
        $this->assertNull($row->completed_at);
        $this->assertNull($row->unknown_outcome_at);
        $this->assertSame(0, Charge::count());

        // Dispatched once more: the row is final, no sixth request.
        $this->attempt($job);
        Http::assertSentCount(5);
    }

    public function test_retry_after_longer_than_the_backoff_is_honoured(): void
    {
        $generation = Generation::factory()->create();
        Http::fake(['*/generations' => Http::response($this->error('rate_limit_error', 'rate_limit_exceeded'), 429, ['Retry-After' => '41'])]);

        $fake = $this->attempt(new SubmitGeneration($generation->id));

        $this->assertTrue($fake->isReleased());
        $this->assertSame(41, $fake->releaseDelay);
    }

    public function test_a_timeout_then_rate_limits_past_the_cap_leave_the_row_queued(): void
    {
        $generation = Generation::factory()->create();
        $rateLimited = [$this->error('rate_limit_error', 'rate_limit_exceeded'), 429, ['Retry-After' => '1']];
        $this->fakeSequence($this->timeout(), $rateLimited, $rateLimited, $rateLimited, $rateLimited, $rateLimited);

        $job = new SubmitGeneration($generation->id);
        $released = [];
        for ($i = 0; $i < 5; $i++) {
            $released[] = $this->attempt($job)->isReleased();
        }

        $this->assertSame([true, true, true, true, false], $released); // the 5th hit the cap and stopped
        $row = $generation->fresh();
        $this->assertSame('queued', $row->status); // never failed: the timeout may have created it
        $this->assertNull($row->failure_code);
        $this->assertNotNull($row->unknown_outcome_at);
        $this->assertSame(5, $row->submit_attempts);
    }

    public function test_the_first_unknown_outcome_time_is_kept(): void
    {
        $generation = Generation::factory()->create();
        $this->fakeSequence($this->timeout(), $this->timeout());

        $job = new SubmitGeneration($generation->id);
        $this->attempt($job);
        $first = $generation->fresh()->unknown_outcome_at;

        Carbon::setTestNow(now()->addMinute());
        $this->attempt($job);

        $this->assertEquals($first, $generation->fresh()->unknown_outcome_at);
    }

    // --- definite rejections ---

    public function test_daily_limit_fails_at_once(): void
    {
        $generation = Generation::factory()->create();
        Http::fake(['*/generations' => Http::response($this->error('rate_limit_error', 'daily_limit_exceeded'), 429)]);

        $fake = $this->attempt(new SubmitGeneration($generation->id));

        $this->assertFalse($fake->isReleased());
        Http::assertSentCount(1);
        $row = $generation->fresh();
        $this->assertSame('failed', $row->status);
        $this->assertSame('daily_limit_exceeded', $row->failure_code);
        $this->assertStringContainsString('daily generation limit', $row->failure_message);
        $this->assertSame(0, Charge::count());
    }

    public function test_content_policy_violation_fails_without_a_charge(): void
    {
        $generation = Generation::factory()->create();
        Http::fake(['*/generations' => Http::response($this->error('invalid_request_error', 'content_policy_violation'), 400)]);

        $this->attempt(new SubmitGeneration($generation->id));

        $row = $generation->fresh();
        $this->assertSame('failed', $row->status);
        $this->assertSame('content_policy_violation', $row->failure_code);
        $this->assertNull($row->pixelforge_id);
        $this->assertNull($row->completed_at);
        $this->assertSame(0, Charge::count());
    }

    public function test_a_definite_rejection_after_an_unknown_outcome_leaves_the_row_queued(): void
    {
        $generation = Generation::factory()->create();
        $this->fakeSequence($this->timeout(), [$this->error('invalid_request_error', 'content_policy_violation'), 400]);

        $job = new SubmitGeneration($generation->id);
        $this->attempt($job);
        $this->attempt($job);

        $row = $generation->fresh();
        $this->assertSame('queued', $row->status);
        $this->assertNotNull($row->unknown_outcome_at);
    }

    public function test_409_marks_an_unknown_outcome_and_never_fails_the_row(): void
    {
        $generation = Generation::factory()->create();
        Http::fake(['*/generations' => Http::response($this->error('idempotency_error', 'idempotency_key_reused'), 409)]);

        $fake = $this->attempt(new SubmitGeneration($generation->id));

        $this->assertFalse($fake->isReleased()); // never resend
        Http::assertSentCount(1);
        $row = $generation->fresh();
        $this->assertSame('queued', $row->status);
        $this->assertNull($row->failure_code);
        $this->assertNotNull($row->unknown_outcome_at);
        $this->assertSame(0, $row->submit_attempts);
    }

    public function test_a_5xx_that_is_not_overloaded_is_an_unknown_outcome(): void
    {
        $generation = Generation::factory()->create();
        Http::fake(['*/generations' => Http::response('<html>bad gateway</html>', 502)]);

        $fake = $this->attempt(new SubmitGeneration($generation->id));

        $this->assertTrue($fake->isReleased());
        $this->assertNotNull($generation->fresh()->unknown_outcome_at);
    }

    public function test_an_unusable_post_response_keeps_the_row_queued_and_marks_an_unknown_outcome(): void
    {
        $generation = Generation::factory()->create();
        $object = $this->queuedObject($generation);
        $object['rates']['output_tokens'] = '40.12345'; // 5 decimals: Rates refuses it
        Http::fake(['*/generations' => Http::response($object, 202)]);
        Log::spy();

        // No exception reaches the queue: the error is permanent, so a queue retry would only
        // replay the POST. The job ends normally and is not released.
        $fake = $this->attempt(new SubmitGeneration($generation->id));

        $this->assertFalse($fake->isReleased());
        $this->assertFalse($fake->hasFailed());
        Http::assertSentCount(1);
        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message) => str_contains($message, $generation->id) && str_contains($message, 'decimal'))
            ->once();
        $row = $generation->fresh();
        $this->assertSame('queued', $row->status);
        $this->assertNull($row->pixelforge_id); // the apply transaction rolled back
        $this->assertNotNull($row->unknown_outcome_at); // Pixelforge has it; we must never write failed
        $this->assertSame('apply_failed', $row->last_submit_error);
    }

    // --- the local failure writer ---

    public function test_record_failure_refuses_unless_the_row_is_still_safe_to_fail(): void
    {
        $writer = new RecordSubmitFailure;
        $cases = [
            'has a pixelforge id (a webhook or POST got there first)' => ['pixelforge_id' => 'gen_X'],
            'processing' => ['status' => 'processing'],
            'succeeded' => ['status' => 'succeeded'],
            'already failed' => ['status' => 'failed', 'failure_code' => 'internal_error'],
            'earlier unknown outcome' => ['unknown_outcome_at' => now()],
        ];

        foreach ($cases as $name => $attributes) {
            $generation = Generation::factory()->create();
            DB::table('generations')->where('id', $generation->id)->update($attributes);
            $before = $generation->fresh()->getAttributes();

            $this->assertFalse($writer->record($generation->id, 'x', 'y'), $name);
            $this->assertSame($before, $generation->fresh()->getAttributes(), $name);
        }

        $this->assertFalse($writer->record('00000000-0000-4000-8000-000000000000', 'x', 'y'));
        $this->assertSame(0, Charge::count());
    }

    public function test_record_failure_writes_a_clean_failure(): void
    {
        $generation = Generation::factory()->create();

        $this->assertTrue((new RecordSubmitFailure)->record($generation->id, 'daily_limit_exceeded', 'Used up.'));

        $row = $generation->fresh();
        $this->assertSame(['failed', 'daily_limit_exceeded', 'Used up.', null], [$row->status, $row->failure_code, $row->failure_message, $row->completed_at]);
        $this->assertSame(0, Charge::count());
    }

    // --- queue mechanics ---

    public function test_the_failed_hook_only_logs(): void
    {
        $generation = Generation::factory()->create();
        Log::spy();

        (new SubmitGeneration($generation->id))->failed(new \RuntimeException('queue gave up'));

        Log::shouldHaveReceived('warning')->once();
        $this->assertSame('queued', $generation->fresh()->status);
        $this->assertNull($generation->fresh()->failure_code);
    }

    public function test_a_real_database_queue_keeps_the_job_through_many_waits(): void
    {
        $generation = Generation::factory()->create();
        Http::fake(['*/generations' => Http::response(...$this->concurrency())]);

        SubmitGeneration::dispatch($generation->id)->onConnection('database');
        // The worker's default is maxTries 1; the job's own `tries = 0` must override it.
        $options = new WorkerOptions(maxTries: 1);

        for ($i = 0; $i < 6; $i++) {
            app('queue.worker')->runNextJob('database', 'default', $options);
            Carbon::setTestNow(now()->addSeconds(6)); // past the 5 s release delay
        }

        Http::assertSentCount(6);
        $this->assertSame(1, DB::table('jobs')->count()); // still waiting, not failed
        $this->assertSame(0, DB::table('failed_jobs')->count());
        // Every release counted as an attempt, far past the worker's maxTries of 1.
        $this->assertGreaterThanOrEqual(6, (int) DB::table('jobs')->value('attempts'));
        $this->assertSame('queued', $generation->fresh()->status);
        $this->assertSame(0, $generation->fresh()->submit_attempts);

        // Past retryUntil() the queue drops the job without running it (queue:work then logs it in
        // failed_jobs). The row is untouched; the poller will pick it up.
        Carbon::setTestNow(now()->addMinutes(20));
        app('queue.worker')->runNextJob('database', 'default', $options);

        $this->assertSame(0, DB::table('jobs')->count());
        Http::assertSentCount(6); // no seventh request
        $this->assertSame('queued', $generation->fresh()->status);
        $this->assertNull($generation->fresh()->failure_code);
    }

    public function test_the_time_limit_is_the_wait_budget_plus_a_margin_and_there_is_no_tries_property(): void
    {
        Carbon::setTestNow('2026-11-18 12:00:00');
        $job = new SubmitGeneration('00000000-0000-4000-8000-000000000000');

        // $tries = 0 would override the worker's --tries; retryUntil() alone makes the worker ignore counts.
        $this->assertFalse(property_exists($job, 'tries'));
        $this->assertSame('2026-11-18 12:15:00', $job->retryUntil()->toDateTimeString());
    }

    // --- release delay never outlives the job ---

    public function test_a_huge_retry_after_is_clamped_to_the_time_left_until_retry_until(): void
    {
        Carbon::setTestNow('2026-11-18 12:00:00');
        $generation = Generation::factory()->create();
        Http::fake(['*/generations' => Http::response($this->error('rate_limit_error', 'rate_limit_exceeded'), 429, ['Retry-After' => '3600'])]);

        $job = new SubmitGeneration($generation->id); // retryUntil 12:15:00
        Carbon::setTestNow('2026-11-18 12:00:10');
        $fake = $this->attempt($job);

        $this->assertTrue($fake->isReleased());
        $this->assertSame(890, $fake->releaseDelay);
    }

    public function test_with_no_time_left_the_job_gives_up_and_the_row_stays_queued(): void
    {
        Carbon::setTestNow('2026-11-18 12:00:00');
        $generation = Generation::factory()->create();
        Http::fake(['*/generations' => Http::response(...$this->overloaded())]);

        $job = new SubmitGeneration($generation->id); // retryUntil 12:15:00
        Carbon::setTestNow('2026-11-18 12:15:00'); // exactly out of time
        $fake = $this->attempt($job);

        $this->assertFalse($fake->isReleased());
        $row = $generation->fresh();
        $this->assertSame('queued', $row->status);
        $this->assertNull($row->failure_code);
    }

    // --- last_submit_error ---

    public function test_last_submit_error_tells_a_409_row_from_a_timeout_row(): void
    {
        [$conflict, $timedOut, $noCode] = [Generation::factory()->create(), Generation::factory()->create(), Generation::factory()->create()];
        // One sequence, one response per call, in the order the three rows are attempted.
        $this->fakeSequence(
            [$this->error('idempotency_error', 'idempotency_key_reused'), 409],
            $this->timeout(),
            ['<html>bad gateway</html>', 502],
        );
        foreach ([$conflict, $timedOut, $noCode] as $row) {
            $this->attempt(new SubmitGeneration($row->id));
        }

        $this->assertSame('idempotency_key_reused', $conflict->fresh()->last_submit_error);
        $this->assertSame('no_response', $timedOut->fresh()->last_submit_error);
        $this->assertSame('http_502', $noCode->fresh()->last_submit_error);
    }

    public function test_last_submit_error_is_overwritten_by_the_latest_failed_attempt_and_empty_on_success(): void
    {
        $generation = Generation::factory()->create();
        $fresh = Generation::factory()->create();
        $this->fakeSequence($this->timeout(), $this->overloaded(), $this->concurrency(), [$this->queuedObject($fresh), 202]);
        $job = new SubmitGeneration($generation->id);

        $seen = [];
        for ($i = 0; $i < 3; $i++) {
            $this->attempt($job);
            $seen[] = $generation->fresh()->last_submit_error;
        }

        $this->assertSame(['no_response', 'overloaded', 'concurrency_limit_exceeded'], $seen);

        $this->attempt(new SubmitGeneration($fresh->id));
        $this->assertNull($fresh->fresh()->last_submit_error);
    }

    // --- what the user may read ---

    public function test_no_raw_provider_text_reaches_failure_message_for_validation_and_unknown_codes(): void
    {
        Log::spy();
        $cases = [
            [422, ['error' => ['type' => 'invalid_request_error', 'code' => 'validation_failed', 'message' => 'The selected size is invalid.', 'param' => 'size']]],
            [418, ['error' => ['type' => 'weird', 'code' => 'teapot_error', 'message' => 'Secret provider words 42']]],
        ];

        $this->fakeSequence(...array_map(fn (array $case) => [$case[1], $case[0]], $cases));

        foreach ($cases as [$status, $body]) {
            $generation = Generation::factory()->create();

            $this->attempt(new SubmitGeneration($generation->id));

            $row = $generation->fresh();
            $this->assertSame('failed', $row->status);
            $this->assertSame('The request could not be processed. Please try again.', $row->failure_message);
            $this->assertSame($body['error']['code'], $row->failure_code);
            // The raw text and the code are logged instead.
            Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, $body['error']['message'])
                && str_contains($message, $body['error']['code']))->once();
        }
    }
}
