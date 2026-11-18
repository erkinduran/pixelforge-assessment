<?php

namespace Tests\Feature\Webhooks;

use App\Models\Charge;
use App\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Generations\PixelforgeObjects;
use Tests\TestCase;

class PixelforgeWebhookTest extends TestCase
{
    use PixelforgeObjects;
    use RefreshDatabase;

    // The id of the real captured succeeded/processing/queued objects (the real failed one has its own).
    private const REAL_GENERATION_ID = 'gen_01M7Q93425KZKQYCVZ1AEEKCXA';

    protected function setUp(): void
    {
        parent::setUp();

        // Freeze the clock so the 300 s window is tested exactly.
        Carbon::setTestNow('2026-11-18 12:00:00');
    }

    // --- helpers ---

    /**
     * An event shaped like the docs' example (L428-441).
     *
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    private function event(string $id, string $type, array $object): array
    {
        return [
            'id' => $id,
            'object' => 'event',
            'type' => $type,
            'created_at' => '2026-11-18T12:00:00Z',
            'data' => ['object' => $object],
        ];
    }

    /** The real failed capture belongs to another generation id; give it the id of the other objects. */
    private function realFailedWithSameId(Generation $generation): array
    {
        return [...$this->realObject('failed', $generation), 'id' => self::REAL_GENERATION_ID];
    }

    /** The header value for $body, signed $secondsOld seconds ago (negative = in the future). */
    private function signatureFor(string $body, int $secondsOld = 0, string $secret = 'test-secret'): string
    {
        $t = now()->timestamp - $secondsOld;

        return 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$body, $secret);
    }

    /** @param array<string, mixed>|string $event */
    private function deliver(array|string $event, int $secondsOld = 0, string $secret = 'test-secret', ?string $header = null): TestResponse
    {
        $body = is_string($event) ? $event : json_encode($event, JSON_THROW_ON_ERROR);

        return $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => $header ?? $this->signatureFor($body, $secondsOld, $secret),
        ], $body);
    }

    private function eventRows(): int
    {
        return DB::table('webhook_events')->count();
    }

    // --- signature ---

    public function test_a_validly_signed_event_is_processed(): void
    {
        $generation = Generation::factory()->create();

        $response = $this->deliver($this->event('evt_1', 'generation.processing', $this->realObject('processing', $generation)));

        $response->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSame('processing', $generation->fresh()->status);
        $this->assertSame(1, $this->eventRows());
        $row = DB::table('webhook_events')->first();
        $this->assertSame('evt_1', $row->event_id);
        $this->assertSame('generation.processing', $row->type);
        $this->assertSame(self::REAL_GENERATION_ID, $row->generation_pixelforge_id);
    }

    public function test_a_tampered_body_is_rejected_and_nothing_is_written(): void
    {
        $generation = Generation::factory()->create();
        $signed = json_encode($this->event('evt_1', 'generation.processing', $this->realObject('processing', $generation)), JSON_THROW_ON_ERROR);
        // The signature belongs to $signed; the body that arrives says "succeeded".
        $tampered = json_encode($this->event('evt_1', 'generation.succeeded', $this->realObject('succeeded', $generation)), JSON_THROW_ON_ERROR);

        $this->deliver($tampered, header: $this->signatureFor($signed))->assertStatus(400);

        $this->assertSame('queued', $generation->fresh()->status);
        $this->assertSame(0, $this->eventRows());
        $this->assertSame(0, Charge::count());
    }

    public function test_a_body_that_differs_by_one_byte_of_whitespace_is_rejected(): void
    {
        $generation = Generation::factory()->create();
        $body = json_encode($this->event('evt_1', 'generation.processing', $this->realObject('processing', $generation)), JSON_THROW_ON_ERROR);

        // Same JSON, different bytes: proves the HMAC is over the raw body, not a re-encoded one.
        $this->deliver($body.' ', header: $this->signatureFor($body))->assertStatus(400);

        $this->assertSame(0, $this->eventRows());
    }

    public function test_a_wrong_secret_is_rejected(): void
    {
        $generation = Generation::factory()->create();
        $event = $this->event('evt_1', 'generation.processing', $this->realObject('processing', $generation));

        $this->deliver($event, secret: 'not-the-secret')->assertStatus(400);

        $this->assertSame('queued', $generation->fresh()->status);
        $this->assertSame(0, $this->eventRows());
    }

    public function test_a_missing_or_garbled_signature_header_is_rejected(): void
    {
        $generation = Generation::factory()->create();
        $event = $this->event('evt_1', 'generation.processing', $this->realObject('processing', $generation));
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $good = $this->signatureFor($body);
        [$t, $v1] = explode(',', $good);
        $wrongV1 = 'v1='.str_repeat('0', 64);

        $garbled = [
            'missing' => '',
            'no scheme' => 'garbage',
            'no timestamp' => $v1,
            'no signature' => $t,
            'empty timestamp' => 't=,'.$v1,
            'non-hex signature' => $t.',v1='.str_repeat('z', 64),
            'short signature' => $t.',v1=abcd',
            'long signature' => $good.'0',
            'non-numeric timestamp' => 't=now,'.$v1,
            'signed-looking timestamp' => 't=+'.substr($t, 2).','.$v1,
            'only wrong v1 values' => $t.','.$wrongV1.','.$wrongV1,
            'unknown key does not stand in for v1' => $t.',v0='.substr($v1, 3),
        ];

        foreach ($garbled as $label => $header) {
            $this->deliver($body, header: $header)->assertStatus(400, "header case: {$label}");
        }

        $this->assertSame(0, $this->eventRows());
        // Negative control: the untouched header on the same body is accepted.
        $this->deliver($body, header: $good)->assertOk();
    }

    public function test_header_variants_the_docs_example_parser_accepts_are_accepted_when_the_hmac_is_valid(): void
    {
        $generation = Generation::factory()->create();
        $wrongV1 = 'v1='.str_repeat('0', 64);

        // Each variant is built from the canonical "t=<t>,v1=<hmac>" of its own body (unique event id).
        $variants = [
            'wrong order' => fn (string $t, string $v1) => $v1.','.$t,
            'unknown extra part' => fn (string $t, string $v1) => $t.','.$v1.',v0=abc',
            'unknown part first' => fn (string $t, string $v1) => 'v0=abc,'.$t.','.$v1,
            'spaces after commas' => fn (string $t, string $v1) => $t.', '.$v1,
            'spaces around parts' => fn (string $t, string $v1) => ' '.$t.' , '.$v1.' ',
            'uppercase hex' => fn (string $t, string $v1) => $t.',v1='.strtoupper(substr($v1, 3)),
            'wrong v1 first, right v1 second' => fn (string $t, string $v1) => $t.','.$wrongV1.','.$v1,
            'right v1 first, wrong v1 second' => fn (string $t, string $v1) => $t.','.$v1.','.$wrongV1,
            'malformed v1 next to the right one' => fn (string $t, string $v1) => $t.',v1=zz,'.$v1,
        ];

        $n = 0;
        foreach ($variants as $label => $build) {
            $n++;
            $body = json_encode($this->event("evt_variant_{$n}", 'generation.processing', $this->realObject('processing', $generation)), JSON_THROW_ON_ERROR);
            [$t, $v1] = explode(',', $this->signatureFor($body));

            $this->deliver($body, header: $build($t, $v1))->assertOk("header case: {$label}");
        }

        $this->assertSame($n, $this->eventRows());
    }

    public function test_a_timestamp_that_is_not_plain_digits_is_rejected_even_when_signed_over_that_exact_text(): void
    {
        $generation = Generation::factory()->create();
        $body = json_encode($this->event('evt_1', 'generation.processing', $this->realObject('processing', $generation)), JSON_THROW_ON_ERROR);

        // "+<now>" casts to a fresh integer, and the HMAC is valid for that text, so only the digit check stops it.
        $t = '+'.now()->getTimestamp();
        $header = 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$body, 'test-secret');

        $this->deliver($body, header: $header)->assertStatus(400);

        $this->assertSame(0, $this->eventRows());
    }

    public function test_a_valid_hmac_in_a_reordered_header_still_needs_a_fresh_timestamp(): void
    {
        $generation = Generation::factory()->create();
        $body = json_encode($this->event('evt_1', 'generation.processing', $this->realObject('processing', $generation)), JSON_THROW_ON_ERROR);
        [$t, $v1] = explode(',', $this->signatureFor($body, 301));

        $this->deliver($body, header: $v1.','.$t)->assertStatus(400);

        $this->assertSame(0, $this->eventRows());
    }

    public function test_a_signed_but_malformed_generation_event_is_logged_without_the_body(): void
    {
        Log::spy();
        $generation = Generation::factory()->create();
        // The succeeded object is the one that carries a signed image_url.
        $imageUrl = $this->realObject('succeeded', $generation)['image_url'];
        $this->assertNotEmpty($imageUrl);
        $noObject = $this->event('evt_bad', 'generation.processing', []);
        $noObject['data'] = [];

        $this->deliver($noObject)->assertStatus(400);

        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message, array $context = []) => $context === ['event_id' => 'evt_bad', 'type' => 'generation.processing']
                && ! str_contains($message.json_encode($context), $imageUrl),
        );
        $this->assertSame(0, $this->eventRows());
    }

    public function test_the_missing_header_case_really_sends_no_header(): void
    {
        $generation = Generation::factory()->create();
        $body = json_encode($this->event('evt_1', 'generation.processing', $this->realObject('processing', $generation)), JSON_THROW_ON_ERROR);

        $this->call('POST', '/webhooks/pixelforge', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertStatus(400);

        $this->assertSame(0, $this->eventRows());
    }

    public function test_a_timestamp_301_seconds_old_is_rejected(): void
    {
        $generation = Generation::factory()->create();
        $event = $this->event('evt_1', 'generation.processing', $this->realObject('processing', $generation));

        $this->deliver($event, secondsOld: 301)->assertStatus(400);

        $this->assertSame(0, $this->eventRows());
        $this->assertSame('queued', $generation->fresh()->status);
    }

    public function test_a_timestamp_301_seconds_in_the_future_is_rejected(): void
    {
        $generation = Generation::factory()->create();
        $event = $this->event('evt_1', 'generation.processing', $this->realObject('processing', $generation));

        $this->deliver($event, secondsOld: -301)->assertStatus(400);

        $this->assertSame(0, $this->eventRows());
        $this->assertSame('queued', $generation->fresh()->status);
    }

    public function test_timestamps_299_seconds_old_and_299_seconds_in_the_future_are_accepted(): void
    {
        $generation = Generation::factory()->create();

        $this->deliver($this->event('evt_old', 'generation.processing', $this->realObject('processing', $generation)), secondsOld: 299)->assertOk();
        $this->deliver($this->event('evt_future', 'generation.processing', $this->realObject('processing', $generation)), secondsOld: -299)->assertOk();

        $this->assertSame(2, $this->eventRows());
    }

    public function test_exactly_300_seconds_old_or_ahead_is_still_accepted(): void
    {
        $generation = Generation::factory()->create();

        $this->deliver($this->event('evt_old', 'generation.processing', $this->realObject('processing', $generation)), secondsOld: 300)->assertOk();
        $this->deliver($this->event('evt_future', 'generation.processing', $this->realObject('processing', $generation)), secondsOld: -300)->assertOk();

        $this->assertSame(2, $this->eventRows());
    }

    public function test_the_timestamp_is_part_of_the_signed_text(): void
    {
        $generation = Generation::factory()->create();
        $body = json_encode($this->event('evt_1', 'generation.processing', $this->realObject('processing', $generation)), JSON_THROW_ON_ERROR);
        $old = $this->signatureFor($body, 400);
        $v1 = explode('v1=', $old)[1];

        // Replay protection: moving t to "now" while keeping an old v1 must not pass.
        $this->deliver($body, header: 't='.now()->timestamp.',v1='.$v1)->assertStatus(400);

        $this->assertSame(0, $this->eventRows());
    }

    public function test_a_missing_webhook_secret_refuses_even_an_event_signed_with_an_empty_key(): void
    {
        config(['services.pixelforge.webhook_secret' => '']);
        $generation = Generation::factory()->create();
        $event = $this->event('evt_1', 'generation.processing', $this->realObject('processing', $generation));

        $this->deliver($event, secret: '')->assertStatus(500);

        $this->assertSame(0, $this->eventRows());
        $this->assertSame('queued', $generation->fresh()->status);
    }

    // --- body ---

    public function test_a_signed_body_that_is_not_json_is_rejected_and_nothing_is_written(): void
    {
        $this->deliver('this is not json')->assertStatus(400);
        $this->deliver('"just a string"')->assertStatus(400);

        $this->assertSame(0, $this->eventRows());
    }

    public function test_a_generation_event_without_an_id_or_object_is_rejected(): void
    {
        $generation = Generation::factory()->create();
        $noId = $this->event('evt_1', 'generation.processing', $this->realObject('processing', $generation));
        unset($noId['id']);
        $noObject = $this->event('evt_2', 'generation.processing', []);
        $noObject['data'] = [];

        $this->deliver($noId)->assertStatus(400);
        $this->deliver($noObject)->assertStatus(400);

        $this->assertSame(0, $this->eventRows());
    }

    // --- duplicates ---

    public function test_a_duplicate_delivery_re_signed_with_a_new_timestamp_is_processed_once(): void
    {
        $generation = Generation::factory()->create();
        $event = $this->event('evt_1', 'generation.succeeded', $this->realObject('succeeded', $generation));

        $this->deliver($event)->assertOk();
        $this->deliver($event, secondsOld: 30)->assertOk();
        $this->deliver($event, secondsOld: 120)->assertOk();

        $this->assertSame(1, $this->eventRows());
        $this->assertSame(1, Charge::count());
        $this->assertSame('succeeded', $generation->fresh()->status);
    }

    public function test_a_duplicate_is_skipped_before_the_generation_is_touched(): void
    {
        $generation = Generation::factory()->create();
        $this->deliver($this->event('evt_1', 'generation.processing', $this->realObject('processing', $generation)))->assertOk();

        // Same event id, but a body that would blow up if apply() ran: it must not run.
        $broken = $this->realObject('succeeded', $generation);
        $broken['usage'] = null;
        $this->deliver($this->event('evt_1', 'generation.succeeded', $broken))->assertOk();

        $this->assertSame('processing', $generation->fresh()->status);
        $this->assertSame(1, $this->eventRows());
    }

    public function test_the_event_id_is_taken_from_the_signed_body_not_from_the_header(): void
    {
        $generation = Generation::factory()->create();
        $event = $this->event('evt_real', 'generation.processing', $this->realObject('processing', $generation));
        $body = json_encode($event, JSON_THROW_ON_ERROR);

        $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => $this->signatureFor($body),
            'HTTP_PIXELFORGE_EVENT_ID' => 'evt_from_header',
        ], $body)->assertOk();

        $this->assertSame(['evt_real'], DB::table('webhook_events')->pluck('event_id')->all());
    }

    // --- order ---

    public function test_succeeded_then_a_late_processing_leaves_it_succeeded_with_one_charge(): void
    {
        $generation = Generation::factory()->create();

        $this->deliver($this->event('evt_succeeded', 'generation.succeeded', $this->realObject('succeeded', $generation)))->assertOk();
        $this->deliver($this->event('evt_processing', 'generation.processing', $this->realObject('processing', $generation)))->assertOk();

        $row = $generation->fresh();
        $this->assertSame('succeeded', $row->status);
        $this->assertSame(1, Charge::count());
        $this->assertSame(14_715_000_000, Charge::firstOrFail()->provider_cost_pico);
        $this->assertSame(2, $this->eventRows());
    }

    public function test_failed_then_succeeded_stays_failed_and_costs_nothing(): void
    {
        $generation = Generation::factory()->create();

        $this->deliver($this->event('evt_failed', 'generation.failed', $this->realFailedWithSameId($generation)))->assertOk();
        $this->deliver($this->event('evt_succeeded', 'generation.succeeded', $this->realObject('succeeded', $generation)))->assertOk();

        $row = $generation->fresh();
        $this->assertSame('failed', $row->status);
        $this->assertSame('generation_failed', $row->failure_code);
        $this->assertNull($row->usage_output);
        $this->assertSame(0, Charge::count());
        $this->assertSame(2, $this->eventRows());
    }

    public function test_succeeded_then_failed_stays_succeeded_with_its_charge(): void
    {
        $generation = Generation::factory()->create();

        $this->deliver($this->event('evt_succeeded', 'generation.succeeded', $this->realObject('succeeded', $generation)))->assertOk();
        $this->deliver($this->event('evt_failed', 'generation.failed', $this->realFailedWithSameId($generation)))->assertOk();

        $this->assertSame('succeeded', $generation->fresh()->status);
        $this->assertSame(1, Charge::count());
    }

    public function test_the_normal_order_queued_processing_succeeded_charges_once(): void
    {
        $generation = Generation::factory()->create();

        $this->deliver($this->event('evt_1', 'generation.processing', $this->realObject('processing', $generation)))->assertOk();
        $this->deliver($this->event('evt_2', 'generation.succeeded', $this->realObject('succeeded', $generation)))->assertOk();

        $this->assertSame('succeeded', $generation->fresh()->status);
        $this->assertSame(1, Charge::count());
    }

    public function test_a_webhook_that_arrives_before_the_post_response_charges_once_with_the_objects_rates(): void
    {
        // Nothing was applied yet: no pixelforge_id, no rates on the row.
        $generation = Generation::factory()->create();
        $this->assertNull($generation->pixelforge_id);
        $this->assertNull($generation->rate_text);

        $this->deliver($this->event('evt_1', 'generation.succeeded', $this->realObject('succeeded', $generation)))->assertOk();

        $row = $generation->fresh();
        $this->assertSame(self::REAL_GENERATION_ID, $row->pixelforge_id);
        $this->assertSame([5_000_000, 10_000_000, 40_000_000, 12_500_000],
            [$row->rate_text, $row->rate_image, $row->rate_output, $row->rate_reasoning]);
        $this->assertSame(1, Charge::count());
        $this->assertSame(18_393_750_000, Charge::firstOrFail()->user_price_pico);
    }

    // --- events that are not about a generation ---

    public function test_ping_returns_200_and_writes_nothing(): void
    {
        $ping = ['id' => 'evt_ping', 'object' => 'event', 'type' => 'ping', 'created_at' => '2026-11-18T12:00:00Z', 'data' => []];

        $this->deliver($ping)->assertOk()->assertExactJson(['ok' => true]);

        $this->assertSame(0, $this->eventRows());
        $this->assertSame(0, Generation::count());
    }

    public function test_an_unknown_event_type_returns_200_and_writes_nothing(): void
    {
        $generation = Generation::factory()->create();
        $event = $this->event('evt_1', 'generation.refunded', $this->realObject('succeeded', $generation));

        $this->deliver($event)->assertOk();

        $this->assertSame(0, $this->eventRows());
        $this->assertSame('queued', $generation->fresh()->status);
        $this->assertSame(0, Charge::count());
    }

    public function test_an_unsigned_ping_is_still_rejected(): void
    {
        $ping = ['id' => 'evt_ping', 'object' => 'event', 'type' => 'ping', 'data' => []];

        $this->deliver($ping, secret: 'not-the-secret')->assertStatus(400);
    }

    // --- not our generation ---

    public function test_an_unknown_local_id_returns_200_records_the_event_and_changes_nothing(): void
    {
        Log::spy();
        $generation = Generation::factory()->create();
        $before = $generation->fresh()->getAttributes();
        $object = $this->realObject('succeeded', $generation);
        $object['metadata']['local_id'] = '11111111-2222-4333-8444-555555555555';

        $this->deliver($this->event('evt_1', 'generation.succeeded', $object))->assertOk();

        $this->assertSame(1, $this->eventRows());
        $this->assertSame($before, $generation->fresh()->getAttributes());
        $this->assertSame(0, Charge::count());
        Log::shouldHaveReceived('warning')->once();
        // A redelivery is then skipped by the event row.
        $this->deliver($this->event('evt_1', 'generation.succeeded', $object), secondsOld: 10)->assertOk();
        $this->assertSame(1, $this->eventRows());
    }

    // --- failure: 5xx and rollback ---

    public function test_when_apply_throws_the_answer_is_500_and_the_event_row_is_rolled_back(): void
    {
        $generation = Generation::factory()->create();
        $before = $generation->fresh()->getAttributes();
        $broken = $this->realObject('succeeded', $generation);
        $broken['usage'] = null;

        $this->deliver($this->event('evt_1', 'generation.succeeded', $broken))->assertStatus(500);

        $this->assertSame(0, $this->eventRows());
        $this->assertSame($before, $generation->fresh()->getAttributes());
        $this->assertSame(0, Charge::count());
    }

    public function test_a_later_redelivery_of_a_corrected_event_processes_normally(): void
    {
        $generation = Generation::factory()->create();
        $broken = $this->realObject('succeeded', $generation);
        $broken['usage'] = null;
        $this->deliver($this->event('evt_1', 'generation.succeeded', $broken))->assertStatus(500);

        // Same event id, now with usage: not seen as a duplicate because the first attempt left no row.
        $this->deliver($this->event('evt_1', 'generation.succeeded', $this->realObject('succeeded', $generation)), secondsOld: 10)->assertOk();

        $this->assertSame(1, $this->eventRows());
        $this->assertSame('succeeded', $generation->fresh()->status);
        $this->assertSame(1, Charge::count());
    }

    // --- route setup ---

    public function test_the_route_has_no_web_group_and_no_auth_middleware(): void
    {
        $webhook = Route::getRoutes()->match(Request::create('/webhooks/pixelforge', 'POST'));
        // Negative control: a normal form route does carry the web group (which holds CSRF and session).
        $login = Route::getRoutes()->match(Request::create('/login', 'POST'));

        $this->assertContains('web', $login->gatherMiddleware());
        $this->assertNotContains('web', $webhook->gatherMiddleware());
        $this->assertNotContains('auth', $webhook->gatherMiddleware());
        $this->assertSame([], $webhook->gatherMiddleware());
    }

    public function test_the_route_only_accepts_post(): void
    {
        $this->get('/webhooks/pixelforge')->assertStatus(405);
    }
}
