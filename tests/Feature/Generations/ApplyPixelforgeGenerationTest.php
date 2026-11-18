<?php

namespace Tests\Feature\Generations;

use App\Generations\ApplyPixelforgeGeneration;
use App\Models\Charge;
use App\Models\Generation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class ApplyPixelforgeGenerationTest extends TestCase
{
    use PixelforgeObjects;
    use RefreshDatabase;

    private function apply(array $object): ?Generation
    {
        return (new ApplyPixelforgeGeneration)->apply($object);
    }

    /** @return array<string, mixed> */
    private function snapshot(Generation $generation): array
    {
        return $generation->fresh()->getAttributes();
    }

    // --- happy path ---

    public function test_queued_then_processing_then_succeeded_charges_once(): void
    {
        $generation = Generation::factory()->create();

        // queued (the POST response): stores the Pixelforge id and the rates, status stays queued.
        $this->apply($this->queuedObject($generation));
        $row = $generation->fresh();
        $this->assertSame('queued', $row->status);
        $this->assertSame('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K', $row->pixelforge_id);
        $this->assertSame([5_000_000, 10_000_000, 40_000_000, 12_500_000],
            [$row->rate_text, $row->rate_image, $row->rate_output, $row->rate_reasoning]);

        $this->apply($this->processingObject($generation));
        $this->assertSame('processing', $generation->fresh()->status);
        $this->assertSame(0, Charge::count());

        $this->apply($this->succeededObject($generation));
        $row = $generation->fresh();
        $this->assertSame('succeeded', $row->status);
        $this->assertSame([12, 765, 6450, 3184],
            [$row->usage_text, $row->usage_image, $row->usage_output, $row->usage_reasoning]);
        $this->assertSame('2026-11-04 14:03:29', $row->completed_at->toDateTimeString());
        $this->assertSame('2026-11-05 14:03:29', $row->image_url_expires_at->toDateTimeString());
        $this->assertStringStartsWith('https://pixelforge.promptedit.dev/images/', $row->image_url);

        $this->assertSame(1, Charge::count());
        $charge = Charge::firstOrFail();
        $this->assertSame($generation->id, $charge->generation_id);
        $this->assertSame($generation->user_id, $charge->user_id);
        $this->assertSame(305_510_000_000, $charge->provider_cost_pico);
        $this->assertSame(381_887_500_000, $charge->user_price_pico);
    }

    public function test_real_captured_objects_queued_processing_succeeded(): void
    {
        $generation = Generation::factory()->create();

        foreach (['queued', 'processing', 'succeeded'] as $name) {
            $this->apply($this->realObject($name, $generation));
        }

        $row = $generation->fresh();
        $this->assertSame('gen_01M7Q93425KZKQYCVZ1AEEKCXA', $row->pixelforge_id);
        $this->assertSame([8, 0, 280, 278],
            [$row->usage_text, $row->usage_image, $row->usage_output, $row->usage_reasoning]);
        $this->assertSame('2026-11-18 12:11:42', $row->completed_at->toDateTimeString());
        $this->assertSame('2026-11-19 12:11:42', $row->image_url_expires_at->toDateTimeString());
        $charge = Charge::firstOrFail();
        $this->assertSame(14_715_000_000, $charge->provider_cost_pico);
        $this->assertSame(18_393_750_000, $charge->user_price_pico);
    }

    public function test_real_captured_failed_object_stores_generation_failed_and_never_charges(): void
    {
        $generation = Generation::factory()->create();

        // The failed capture comes from another generation than the others, so it is applied alone.
        $this->apply($this->realObject('failed', $generation));

        $row = $generation->fresh();
        $this->assertSame('failed', $row->status);
        $this->assertSame('generation_failed', $row->failure_code);
        $this->assertSame('The model could not produce an image for this prompt. You were not charged.', $row->failure_message);
        $this->assertSame('2026-11-18 12:12:41', $row->completed_at->toDateTimeString());
        $this->assertSame(0, Charge::count());
    }

    public function test_a_real_succeeded_object_with_usage_null_throws_and_changes_nothing(): void
    {
        // The real API sends every key, null when empty, so "usage": null is the case that matters.
        $generation = Generation::factory()->create();
        $before = $this->snapshot($generation);
        $object = $this->realObject('succeeded', $generation);
        $object['usage'] = null;

        $this->assertThrows(fn () => $this->apply($object), InvalidArgumentException::class);

        $this->assertSame($before, $this->snapshot($generation));
        $this->assertSame(0, Charge::count());
    }

    public function test_a_timestamp_with_an_offset_is_stored_as_the_utc_instant(): void
    {
        $generation = Generation::factory()->create();
        $object = $this->succeededObject($generation);
        $object['completed_at'] = '2026-11-18T23:59:00-03:00';
        $object['image_url_expires_at'] = '2026-11-19T23:59:00-03:00';

        $this->apply($object);

        $row = $generation->fresh();
        $this->assertSame(0, $row->completed_at->getOffset());
        $this->assertSame('2026-11-19 02:59:00', $row->completed_at->toDateTimeString());
        $this->assertSame('2026-11-20 02:59:00', $row->image_url_expires_at->toDateTimeString());
    }

    public function test_webhook_first_succeeded_fills_rates_and_charges_once(): void
    {
        $generation = Generation::factory()->create();

        // The first object we ever see is the succeeded one: no queued or processing before it.
        $this->apply($this->succeededObject($generation));

        $row = $generation->fresh();
        $this->assertSame('succeeded', $row->status);
        $this->assertSame(5_000_000, $row->rate_text);
        $this->assertSame(12_500_000, $row->rate_reasoning);
        $this->assertSame(1, Charge::count());
        $this->assertSame(305_510_000_000, Charge::firstOrFail()->provider_cost_pico);
    }

    public function test_rates_are_never_overwritten_and_the_charge_uses_the_stored_rates(): void
    {
        $generation = Generation::factory()->create();
        $this->apply($this->queuedObject($generation));

        // A later object carries different rates (e.g. /pricing changed): they must be ignored.
        $succeeded = $this->succeededObject($generation);
        $succeeded['rates']['output_tokens'] = '80.00';
        $this->apply($succeeded);

        $this->assertSame(40_000_000, $generation->fresh()->rate_output);
        $this->assertSame(305_510_000_000, Charge::firstOrFail()->provider_cost_pico);
    }

    public function test_a_later_queued_object_does_not_move_a_processing_row_back(): void
    {
        $generation = Generation::factory()->create();
        $this->apply($this->processingObject($generation));

        $this->apply($this->queuedObject($generation));

        $this->assertSame('processing', $generation->fresh()->status);
    }

    public function test_the_row_is_found_by_pixelforge_id_when_metadata_has_no_local_id(): void
    {
        $generation = Generation::factory()->create(['pixelforge_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);
        $object = $this->processingObject($generation);
        $object['metadata'] = ['order_id' => '1042'];

        $result = $this->apply($object);

        $this->assertSame($generation->id, $result?->id);
        $this->assertSame('processing', $generation->fresh()->status);
    }

    // --- duplicates ---

    public function test_the_same_succeeded_object_twice_makes_one_charge_and_changes_nothing(): void
    {
        $generation = Generation::factory()->create();
        $this->apply($this->succeededObject($generation));
        $before = $this->snapshot($generation);

        $this->apply($this->succeededObject($generation));

        $this->assertSame(1, Charge::count());
        $this->assertSame($before, $this->snapshot($generation));
    }

    public function test_two_applies_in_one_transaction_make_one_charge(): void
    {
        $generation = Generation::factory()->create();

        // The webhook controller will wrap apply() in its own transaction.
        DB::transaction(function () use ($generation) {
            $this->apply($this->succeededObject($generation));
            $this->apply($this->succeededObject($generation));
        });

        $this->assertSame(1, Charge::count());
    }

    public function test_an_existing_charge_is_skipped_without_an_error_and_the_connection_stays_usable(): void
    {
        $generation = Generation::factory()->create(['status' => 'processing']);
        // The charge exists although the row is not final yet: the status guard cannot help here,
        // only the insert itself can. insertOrIgnore must skip it instead of raising.
        Charge::forceCreate([
            'generation_id' => $generation->id,
            'user_id' => $generation->user_id,
            'provider_cost_pico' => 1,
            'user_price_pico' => 2,
        ]);

        $this->apply($this->succeededObject($generation));

        $this->assertSame(1, Charge::count());
        $this->assertSame(1, Charge::firstOrFail()->provider_cost_pico, 'the first charge stays as it was');
        $this->assertSame('succeeded', $generation->fresh()->status);
    }

    public function test_a_plain_second_charge_insert_is_refused_by_the_unique_index(): void
    {
        // Negative control for the test above: without insertOrIgnore the database says no.
        $generation = Generation::factory()->create();
        $charge = [
            'generation_id' => $generation->id,
            'user_id' => $generation->user_id,
            'provider_cost_pico' => 1,
            'user_price_pico' => 2,
        ];
        Charge::forceCreate($charge);

        $this->assertThrows(
            fn () => DB::transaction(fn () => Charge::forceCreate($charge)),
            QueryException::class,
        );
        $this->assertSame(1, Charge::count());
    }

    // --- out of order ---

    public function test_processing_after_succeeded_changes_nothing(): void
    {
        $generation = Generation::factory()->create();
        $this->apply($this->succeededObject($generation));
        $before = $this->snapshot($generation);

        $this->apply($this->processingObject($generation));

        $this->assertSame($before, $this->snapshot($generation));
        $this->assertSame(1, Charge::count());
    }

    public function test_processing_after_failed_changes_nothing(): void
    {
        $generation = Generation::factory()->create();
        $this->apply($this->failedObject($generation));
        $before = $this->snapshot($generation);

        $this->apply($this->processingObject($generation));

        $this->assertSame($before, $this->snapshot($generation));
    }

    public function test_succeeded_after_failed_stays_failed_and_makes_no_charge(): void
    {
        $generation = Generation::factory()->create();
        $this->apply($this->failedObject($generation));
        $before = $this->snapshot($generation);
        Log::spy();

        $this->apply($this->succeededObject($generation));

        $this->assertSame('failed', $generation->fresh()->status);
        $this->assertSame($before, $this->snapshot($generation));
        $this->assertSame(0, Charge::count());
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_failed_after_succeeded_stays_succeeded_and_keeps_its_one_charge(): void
    {
        $generation = Generation::factory()->create();
        $this->apply($this->succeededObject($generation));
        $before = $this->snapshot($generation);

        $this->apply($this->failedObject($generation));

        $this->assertSame('succeeded', $generation->fresh()->status);
        $this->assertSame($before, $this->snapshot($generation));
        $this->assertSame(1, Charge::count());
    }

    public function test_a_final_row_is_not_touched_even_to_fill_in_missing_rates(): void
    {
        $generation = Generation::factory()->create();
        $failed = $this->failedObject($generation);
        unset($failed['rates']);
        $this->apply($failed);
        $before = $this->snapshot($generation);

        $this->apply($this->queuedObject($generation));

        $this->assertNull($generation->fresh()->rate_text);
        $this->assertSame($before, $this->snapshot($generation));
    }

    public function test_the_row_is_selected_with_for_update(): void
    {
        $generation = Generation::factory()->create();
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->apply($this->processingObject($generation));

        $locking = array_filter($queries, fn (string $sql) => str_contains($sql, 'from "generations"') && str_contains($sql, 'for update'));
        $this->assertCount(1, $locking);
    }

    public function test_the_row_found_by_pixelforge_id_is_also_selected_with_for_update(): void
    {
        $generation = Generation::factory()->create(['pixelforge_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);
        $object = $this->processingObject($generation);
        $object['metadata'] = [];
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->apply($object);

        $locking = array_filter($queries, fn (string $sql) => str_contains($sql, '"pixelforge_id" = ') && str_contains($sql, 'for update'));
        $this->assertCount(1, $locking);
    }

    // --- failures ---

    public function test_failed_stores_the_error_and_never_charges(): void
    {
        $generation = Generation::factory()->create();
        $this->apply($this->processingObject($generation));

        $this->apply($this->failedObject($generation));

        $row = $generation->fresh();
        $this->assertSame('failed', $row->status);
        $this->assertSame('internal_error', $row->failure_code);
        $this->assertStringContainsString('You were not charged', $row->failure_message);
        $this->assertSame('2026-11-04 14:03:25', $row->completed_at->toDateTimeString());
        $this->assertNull($row->usage_text);
        $this->assertNull($row->image_url);
        $this->assertSame(0, Charge::count());
    }

    public function test_succeeded_without_usage_throws_and_changes_nothing(): void
    {
        $generation = Generation::factory()->create();
        $before = $this->snapshot($generation);
        $object = $this->succeededObject($generation);
        $object['usage'] = null;

        $this->assertThrows(fn () => $this->apply($object), InvalidArgumentException::class);

        // Not even the Pixelforge id and the rates from the same object were kept.
        $this->assertSame($before, $this->snapshot($generation));
        $this->assertSame(0, Charge::count());
    }

    public function test_succeeded_without_rates_throws_and_changes_nothing(): void
    {
        $generation = Generation::factory()->create();
        $before = $this->snapshot($generation);
        $object = $this->succeededObject($generation);
        unset($object['rates']);

        $this->assertThrows(fn () => $this->apply($object), RuntimeException::class);

        $this->assertSame($before, $this->snapshot($generation));
        $this->assertSame(0, Charge::count());
    }

    public function test_succeeded_without_completed_at_throws_and_changes_nothing(): void
    {
        $generation = Generation::factory()->create();
        $before = $this->snapshot($generation);
        $object = $this->succeededObject($generation);
        $object['completed_at'] = null;

        $this->assertThrows(fn () => $this->apply($object), InvalidArgumentException::class);

        $this->assertSame($before, $this->snapshot($generation));
        $this->assertSame(0, Charge::count());
    }

    public function test_a_rate_with_too_many_decimals_throws_and_changes_nothing(): void
    {
        // Money::parseRatePerMillion refuses to round; the whole apply rolls back.
        $generation = Generation::factory()->create();
        $before = $this->snapshot($generation);
        $object = $this->succeededObject($generation);
        $object['rates']['output_tokens'] = '40.00001';

        $this->assertThrows(fn () => $this->apply($object));

        $this->assertSame($before, $this->snapshot($generation));
        $this->assertSame(0, Charge::count());
    }

    public function test_an_object_without_id_or_with_an_unknown_status_throws(): void
    {
        $generation = Generation::factory()->create();

        $noId = $this->queuedObject($generation);
        unset($noId['id']);
        $this->assertThrows(fn () => $this->apply($noId), InvalidArgumentException::class);

        $unknownStatus = [...$this->queuedObject($generation), 'status' => 'cancelled'];
        $this->assertThrows(fn () => $this->apply($unknownStatus), InvalidArgumentException::class);
    }

    // --- finding the row ---

    public function test_a_valid_but_unknown_local_id_returns_null(): void
    {
        $generation = Generation::factory()->create();
        $object = $this->queuedObject($generation);
        $object['metadata']['local_id'] = '0199b3c2-7d4e-7a1b-8c3d-5e6f7a8b9c0d';

        $this->assertNull($this->apply($object));
        $this->assertNull($generation->fresh()->pixelforge_id);
    }

    public function test_a_malformed_local_id_returns_null_and_the_transaction_stays_usable(): void
    {
        $generation = Generation::factory()->create();
        $object = $this->queuedObject($generation);
        $object['metadata']['local_id'] = 'not-a-uuid';

        // Inside an outer transaction, like the webhook controller: if Postgres had raised,
        // every query after this would fail with "current transaction is aborted".
        $result = DB::transaction(function () use ($object) {
            $result = $this->apply($object);
            $this->assertSame(1, Generation::count());

            return $result;
        });

        $this->assertNull($result);
        $this->assertNull($generation->fresh()->pixelforge_id);
    }

    public function test_postgres_really_rejects_a_malformed_uuid(): void
    {
        // Negative control for the test above: this is why Str::isUuid runs before the query.
        $this->assertThrows(
            fn () => DB::transaction(fn () => Generation::query()->whereKey('not-a-uuid')->first()),
            QueryException::class,
        );
    }

    public function test_a_different_pixelforge_id_for_the_same_row_throws(): void
    {
        $generation = Generation::factory()->create(['pixelforge_id' => 'gen_AAAAAAAAAAAAAAAAAAAAAAAAAA']);
        $before = $this->snapshot($generation);

        // The docs' object has another id (gen_01JC...), but carries our local_id.
        $this->assertThrows(fn () => $this->apply($this->succeededObject($generation)), RuntimeException::class);

        $this->assertSame($before, $this->snapshot($generation));
        $this->assertSame(0, Charge::count());
    }

    // --- types ---

    public function test_money_and_token_columns_are_integers_after_reloading(): void
    {
        $generation = Generation::factory()->create();
        $this->apply($this->succeededObject($generation));

        $row = Generation::findOrFail($generation->id);
        foreach ([
            'estimate_min_pico', 'estimate_max_pico',
            'rate_text', 'rate_image', 'rate_output', 'rate_reasoning',
            'usage_text', 'usage_image', 'usage_output', 'usage_reasoning',
        ] as $column) {
            $this->assertTrue(is_int($row->{$column}), "{$column} is not an int");
        }

        $charge = Charge::firstOrFail();
        $this->assertTrue(is_int($charge->provider_cost_pico));
        $this->assertTrue(is_int($charge->user_price_pico));
        $this->assertSame($charge->id, $row->charge?->id);
    }

    public function test_the_database_session_runs_in_utc(): void
    {
        // config/database.php sets 'timezone' => 'UTC' on the pgsql connection, so the
        // timestamptz column completed_at never depends on the server's or session's zone.
        $this->assertSame('UTC', DB::selectOne('show timezone')->TimeZone);
    }
}
