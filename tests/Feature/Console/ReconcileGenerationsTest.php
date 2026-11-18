<?php

namespace Tests\Feature\Console;

use App\Console\Commands\ReconcileGenerations;
use App\Generations\SubmitGeneration;
use App\Models\Charge;
use App\Models\Generation;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Generations\PixelforgeObjects;
use Tests\TestCase;

class ReconcileGenerationsTest extends TestCase
{
    use PixelforgeObjects;
    use RefreshDatabase;

    private const PF_ID = 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K';

    /** A row with no news for $minutes minutes. */
    private function row(array $attributes = [], int $minutes = 10): Generation
    {
        return Generation::factory()->create([...$attributes, 'updated_at' => now()->subMinutes($minutes)]);
    }

    /** @return array<int, Request> */
    private function sent(): array
    {
        return Http::recorded()->map(fn (array $pair) => $pair[0])->values()->all();
    }

    private function run_(): void
    {
        $this->artisan('pixelforge:reconcile')->assertSuccessful();
    }

    // --- rows with a Pixelforge id: GET and apply ---

    public function test_a_succeeded_answer_finishes_the_row_with_one_charge(): void
    {
        $row = $this->row(['pixelforge_id' => self::PF_ID, 'status' => 'processing']);
        Http::fake(['*/generations/'.self::PF_ID => Http::response($this->succeededObject($row), 200)]);

        $this->run_();
        $this->run_(); // the row is final now: nothing more is asked

        $this->assertSame('succeeded', $row->fresh()->status);
        $this->assertSame(1, Charge::count());
        Http::assertSentCount(1);
        $this->assertStringEndsWith('/generations/'.self::PF_ID, $this->sent()[0]->url());
        $this->assertTrue($this->sent()[0]->hasHeader('Authorization'));
    }

    public function test_a_failed_answer_from_pixelforge_finishes_the_row_without_a_charge(): void
    {
        $row = $this->row(['pixelforge_id' => self::PF_ID]);
        Http::fake(['*/generations/'.self::PF_ID => Http::response($this->failedObject($row), 200)]);

        $this->run_();

        $this->assertSame('failed', $row->fresh()->status);
        $this->assertSame(0, Charge::count());
    }

    public function test_a_still_processing_answer_moves_the_status_forward_and_nothing_else(): void
    {
        $queued = $this->row(['pixelforge_id' => self::PF_ID]);
        Http::fake(['*/generations/'.self::PF_ID => Http::response($this->processingObject($queued), 200)]);

        $this->run_();

        $row = $queued->fresh();
        $this->assertSame('processing', $row->status);
        $this->assertNull($row->completed_at);
        $this->assertSame(0, Charge::count());

        // Asked again later and still processing: unchanged, still not final.
        $row->forceFill(['updated_at' => now()->subMinutes(10)])->saveQuietly();
        $this->run_();
        $this->assertSame('processing', $queued->fresh()->status);
        Http::assertSentCount(2);
    }

    public function test_get_errors_never_fail_a_row_and_do_not_stop_the_run(): void
    {
        $bad = [
            $this->row(['pixelforge_id' => 'gen_503'], 20),
            $this->row(['pixelforge_id' => 'gen_404'], 19),
            $this->row(['pixelforge_id' => 'gen_html'], 18),
            $this->row(['pixelforge_id' => 'gen_timeout'], 17),
        ];
        $good = $this->row(['pixelforge_id' => self::PF_ID], 5);
        Http::fake([
            '*/generations/gen_503' => Http::response(['error' => ['code' => 'overloaded', 'message' => 'x']], 503),
            '*/generations/gen_404' => Http::response(['error' => ['code' => 'not_found', 'message' => 'x']], 404),
            '*/generations/gen_html' => Http::response('<html>bad gateway</html>', 200),
            '*/generations/gen_timeout' => fn () => throw new ConnectionException('cURL error 28: timed out'),
            '*/generations/'.self::PF_ID => Http::response($this->succeededObject($good), 200),
        ]);

        $this->run_();

        foreach ($bad as $row) {
            $fresh = $row->fresh();
            $this->assertSame('queued', $fresh->status, $row->pixelforge_id);
            $this->assertNull($fresh->failure_code);
        }
        $this->assertSame('succeeded', $good->fresh()->status); // the run went on
        $this->assertSame(1, Charge::count());
    }

    public function test_an_answer_that_cannot_be_applied_leaves_the_row_alone(): void
    {
        $row = $this->row(['pixelforge_id' => self::PF_ID]);
        $object = $this->succeededObject($row);
        $object['usage'] = null; // a succeeded object without usage: apply throws
        Http::fake(['*/generations/'.self::PF_ID => Http::response($object, 200)]);

        $this->run_();

        $this->assertSame('queued', $row->fresh()->status);
        $this->assertSame(0, Charge::count());
    }

    public function test_final_rows_and_fresh_rows_are_not_asked_about(): void
    {
        $this->row(['pixelforge_id' => 'gen_a', 'status' => 'succeeded']);
        $this->row(['pixelforge_id' => 'gen_b', 'status' => 'failed']);
        $this->row(['pixelforge_id' => 'gen_c'], 1); // news a minute ago
        Http::fake();

        $this->run_();

        Http::assertNothingSent();
    }

    // --- rows without an id: re-dispatch ---

    public function test_a_queued_row_without_an_id_is_dispatched_once_even_if_the_command_runs_again(): void
    {
        Queue::fake();
        $row = $this->row();

        $this->run_();
        // Same row, old again: the unique lock of the first (still alive) job stops a second one.
        $row->forceFill(['updated_at' => now()->subMinutes(30)])->saveQuietly();
        $this->run_();

        Queue::assertPushedTimes(SubmitGeneration::class, 1);
        Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->generationId === $row->id);
    }

    public function test_rows_inside_the_window_and_conflict_rows_are_not_dispatched(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['object' => 'list', 'data' => [], 'has_more' => false, 'next_cursor' => null], 200)]);
        $this->row([], 4); // a live job is probably still working on it
        $this->row(['last_submit_error' => 'idempotency_key_reused']);
        $this->row(['last_submit_error' => 'http_409']);
        $this->row(['pixelforge_id' => 'gen_x', 'status' => 'succeeded']); // final

        $this->run_();

        Queue::assertNothingPushed();
    }

    public function test_other_last_errors_are_dispatched(): void
    {
        Queue::fake();
        $timeout = $this->row(['last_submit_error' => 'no_response']);
        $overloaded = $this->row(['last_submit_error' => 'overloaded']);
        $applyFailed = $this->row(['last_submit_error' => 'apply_failed']);

        $this->run_();

        Queue::assertPushedTimes(SubmitGeneration::class, 3);
        foreach ([$timeout, $overloaded, $applyFailed] as $row) {
            Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->generationId === $row->id);
        }
    }

    public function test_an_apply_failed_row_older_than_a_day_is_skipped_and_the_skip_is_logged_once_with_a_count(): void
    {
        Queue::fake();
        Log::spy();
        // created_at is set at creation (saving a row again would move updated_at to now).
        $oldAt = now()->subHours(25);
        $old = [
            $this->row(['last_submit_error' => 'apply_failed', 'created_at' => $oldAt]),
            $this->row(['last_submit_error' => 'apply_failed', 'created_at' => $oldAt]),
        ];
        $recent = $this->row(['last_submit_error' => 'apply_failed']);
        $olderOtherError = $this->row(['last_submit_error' => 'no_response', 'created_at' => $oldAt]); // only apply_failed gives up

        $this->run_();

        Queue::assertPushedTimes(SubmitGeneration::class, 2);
        foreach ([$recent, $olderOtherError] as $row) {
            Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->generationId === $row->id);
        }
        foreach ($old as $row) {
            Queue::assertNotPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->generationId === $row->id);
            $this->assertSame('queued', $row->fresh()->status); // left alone, never failed by the command
        }
        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message) => str_contains($message, 'skipped 2 generation(s)') && ! str_contains($message, $old[0]->id))
            ->once();
    }

    public function test_no_skip_is_logged_when_no_apply_failed_row_is_old(): void
    {
        Queue::fake();
        Log::spy();
        $this->row(['last_submit_error' => 'apply_failed']);

        $this->run_();

        Queue::assertPushedTimes(SubmitGeneration::class, 1);
        Log::shouldNotHaveReceived('info');
    }

    public function test_the_unique_lock_works_with_the_database_cache_store_and_the_real_queue(): void
    {
        config(['cache.default' => 'database', 'queue.default' => 'database']);
        $row = $this->row();

        $this->run_();
        $row->forceFill(['updated_at' => now()->subMinutes(30)])->saveQuietly();
        $this->run_();
        SubmitGeneration::dispatch($row->id); // the controller could also try

        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('cache_locks')->where('key', 'like', '%laravel_unique_job%')->count());
    }

    public function test_the_job_is_unique_per_generation_for_as_long_as_it_lives(): void
    {
        Carbon::setTestNow('2026-11-18 12:00:00');
        $job = new SubmitGeneration('11111111-1111-4111-8111-111111111111');

        $this->assertSame('11111111-1111-4111-8111-111111111111', $job->uniqueId());
        $this->assertSame(900, $job->uniqueFor());
        $this->assertSame(900, (int) now()->diffInSeconds($job->retryUntil(), true)); // the same window as the job's own limit
    }

    // --- rows after a 409: page the list ---

    /** @param  array<int, array<string, mixed>>  $items */
    private function listPage(array $items, ?string $next): PromiseInterface
    {
        return Http::response(['object' => 'list', 'data' => $items, 'has_more' => $next !== null, 'next_cursor' => $next], 200);
    }

    public function test_a_conflict_row_is_found_in_the_list_and_applied(): void
    {
        Queue::fake();
        $row = $this->row(['last_submit_error' => 'idempotency_key_reused']);
        $other = Generation::factory()->create(['updated_at' => now()]);
        Http::fake(['*/generations*' => $this->listPage([
            $this->queuedObject($other), // not wanted: someone else's row of ours is not touched
            $this->succeededObject($row),
        ], null)]);

        $this->run_();

        $fresh = $row->fresh();
        $this->assertSame('succeeded', $fresh->status);
        $this->assertSame(self::PF_ID, $fresh->pixelforge_id);
        $this->assertSame(1, Charge::count());
        $this->assertNull($other->fresh()->pixelforge_id);
        Queue::assertNothingPushed(); // a 409 row is never re-POSTed
        Http::assertSentCount(1);
        $this->assertSame('100', $this->queryOf($this->sent()[0])['limit']);
    }

    public function test_a_listed_object_that_cannot_be_applied_is_not_searched_for_again_next_minute(): void
    {
        $row = $this->row(['last_submit_error' => 'idempotency_key_reused']);
        $object = $this->succeededObject($row);
        $object['usage'] = null; // a succeeded object without usage: apply throws
        Http::fake(['*/generations*' => $this->listPage([$object], null)]);

        $this->run_();

        $fresh = $row->fresh();
        $this->assertSame('queued', $fresh->status); // never failed, never charged
        $this->assertNull($fresh->pixelforge_id);
        $this->assertSame(0, Charge::count());
        $this->assertTrue($fresh->updated_at->greaterThan(now()->subMinute())); // touched: back of the line
        Http::assertSentCount(1);

        $this->run_(); // inside the window now: the list is not requested again

        Http::assertSentCount(1);
    }

    /** @return array<string, string> */
    private function queryOf(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query;
    }

    public function test_the_list_is_paged_with_the_cursor_and_stops_when_everything_is_found(): void
    {
        $row = $this->row(['last_submit_error' => 'http_409']);
        $filler = array_fill(0, 3, ['id' => 'gen_filler', 'metadata' => ['local_id' => 'not-ours']]);
        Http::fake(['*/generations*' => Http::sequence()
            ->push(['object' => 'list', 'data' => $filler, 'has_more' => true, 'next_cursor' => 'CURSOR2'], 200)
            ->push(['object' => 'list', 'data' => [$this->processingObject($row)], 'has_more' => true, 'next_cursor' => 'CURSOR3'], 200)]);

        $this->run_();

        $this->assertSame('processing', $row->fresh()->status);
        $this->assertSame(self::PF_ID, $row->fresh()->pixelforge_id);
        Http::assertSentCount(2); // found on page 2: page 3 is not requested
        $this->assertArrayNotHasKey('cursor', $this->queryOf($this->sent()[0]));
        $this->assertSame('CURSOR2', $this->queryOf($this->sent()[1])['cursor']);
    }

    public function test_a_conflict_row_that_is_not_in_the_newest_pages_is_left_alone(): void
    {
        $row = $this->row(['last_submit_error' => 'idempotency_key_reused']);
        $filler = [['id' => 'gen_filler', 'metadata' => ['local_id' => 'not-ours']]];
        Http::fake(['*/generations*' => $this->listPage($filler, 'MORE')]); // always another page

        $this->run_();

        $fresh = $row->fresh();
        $this->assertSame('queued', $fresh->status);
        $this->assertNull($fresh->pixelforge_id);
        $this->assertNull($fresh->failure_code);
        Http::assertSentCount(ReconcileGenerations::LIST_PAGES); // at most N pages per run
    }

    public function test_a_failing_list_request_changes_nothing_and_never_leads_to_a_re_post(): void
    {
        Queue::fake();
        $row = $this->row(['last_submit_error' => 'http_409']);
        Http::fake(['*/generations*' => Http::response(['error' => ['code' => 'overloaded', 'message' => 'x']], 503)]);

        $this->run_();

        $this->assertSame('queued', $row->fresh()->status);
        Http::assertSentCount(1);
        Queue::assertNothingPushed();
    }

    // --- budget ---

    public function test_one_run_makes_at_most_the_budget_of_requests_oldest_rows_first(): void
    {
        $rows = [];
        for ($i = 0; $i < 30; $i++) {
            $rows[] = $this->row(['pixelforge_id' => sprintf('gen_%02d', $i)], 100 - $i); // gen_00 is the oldest
        }
        Http::fake(['*/generations/*' => fn (Request $request) => Http::response(['id' => basename($request->url()), 'status' => 'queued', 'metadata' => []], 200)]);

        $this->run_();

        $polled = collect($this->sent())->map(fn (Request $r) => basename($r->url()))->all();
        $this->assertCount(ReconcileGenerations::REQUEST_BUDGET, $polled);
        $this->assertSame(array_map(fn (int $i) => sprintf('gen_%02d', $i), range(0, 19)), $polled);

        // The next run starts with the rows that were not asked yet (polled rows were touched).
        Http::fake(['*/generations/*' => fn (Request $request) => Http::response(['id' => basename($request->url()), 'status' => 'queued', 'metadata' => []], 200)]);
        $this->run_();
        $second = collect(Http::recorded())->map(fn (array $pair) => basename($pair[0]->url()))->take(-10)->values()->all();
        $this->assertSame(array_map(fn (int $i) => sprintf('gen_%02d', $i), range(20, 29)), $second);
    }

    public function test_the_budget_is_shared_between_polling_listing_and_dispatching(): void
    {
        Queue::fake();
        for ($i = 0; $i < ReconcileGenerations::REQUEST_BUDGET; $i++) {
            $this->row(['pixelforge_id' => sprintf('gen_%02d', $i)]);
        }
        $this->row(['last_submit_error' => 'http_409']);
        $this->row(); // would be dispatched
        Http::fake(['*/generations/*' => fn (Request $request) => Http::response(['id' => basename($request->url()), 'status' => 'queued', 'metadata' => []], 200)]);

        $this->run_();

        Http::assertSentCount(ReconcileGenerations::REQUEST_BUDGET); // the polls used it all
        Queue::assertNothingPushed();
    }

    // --- scheduling ---

    public function test_the_command_is_scheduled_every_minute_without_overlapping(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'pixelforge:reconcile'));

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(10, $event->expiresAt);
    }

    public function test_nothing_to_do_means_no_requests(): void
    {
        Http::fake();

        $this->run_();

        Http::assertNothingSent();
        $this->assertSame(0, Generation::count());
    }
}
