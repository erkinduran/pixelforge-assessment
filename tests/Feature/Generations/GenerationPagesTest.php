<?php

namespace Tests\Feature\Generations;

use App\Generations\SubmitGeneration;
use App\Models\Charge;
use App\Models\Generation;
use App\Models\User;
use App\Pricing\PricingUnavailableException;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GenerationPagesTest extends TestCase
{
    use PricingFake;
    use RefreshDatabase;

    /** The docs' "most a generation can cost" example: high, 1536x1024, 400 characters, 2 reference images. */
    /** @return array<string, mixed> */
    private function docsMaxRequest(): array
    {
        return [
            'prompt' => str_repeat('a', 400),
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_image_urls' => ['https://example.com/a.png', 'https://example.org/b.jpg'],
            'submission_id' => (string) Str::uuid(), // the real submit needs one; the estimate ignores it
        ];
    }

    // --- estimate ---

    public function test_estimate_matches_the_docs_maximum_example_as_user_price_strings(): void
    {
        $this->fakePricing();

        $response = $this->actingAs(User::factory()->create())->postJson(route('generations.estimate'), $this->docsMaxRequest());

        $response->assertOk()->assertJsonPath('estimate.max_price', '$0.46734375'); // 0.373875 x 5/4
        $estimate = $response->json('estimate');
        $this->assertIsString($estimate['min_price']);
        // 100 / 4 = 25 estimated text tokens... here 400 chars = 100 tokens, -15% / +15%.
        $this->assertSame(['text' => 85, 'image' => 1530, 'output' => 6450, 'reasoning' => 1000], $estimate['tokens']['min']);
        $this->assertSame(['text' => 115, 'image' => 1530, 'output' => 6450, 'reasoning' => 8000], $estimate['tokens']['max']);
        // min: 85x5 + 1530x10 + 6450x40 + 1000x12.5 = 0.000425 + 0.0153 + 0.258 + 0.0125 = 0.286225; x 5/4 = 0.35778125
        $this->assertSame('$0.35778125', $estimate['min_price']);
    }

    public function test_estimate_response_never_shows_provider_cost_markup_or_rates(): void
    {
        $this->fakePricing();

        $json = $this->actingAs(User::factory()->create())->postJson(route('generations.estimate'), $this->docsMaxRequest())->getContent();

        foreach (['provider', 'markup', 'rate', 'pico', '0.373875', '0.286225'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) $json);
        }
    }

    public function test_estimate_needs_login(): void
    {
        $this->postJson(route('generations.estimate'), $this->docsMaxRequest())->assertUnauthorized();
    }

    public function test_estimate_without_pricing_is_a_clear_503_and_nothing_is_cached(): void
    {
        Http::fake(['*/pricing' => Http::response(['error' => ['code' => 'overloaded', 'message' => 'x']], 503)]);

        $response = $this->actingAs(User::factory()->create())->postJson(route('generations.estimate'), $this->docsMaxRequest());

        $response->assertStatus(503)->assertJsonPath('message', fn (string $m) => str_contains($m, 'Pricing is unavailable'));
        $this->assertFalse(Cache::has('pixelforge.pricing'));
    }

    public function test_pricing_that_cannot_be_parsed_is_unavailable_too(): void
    {
        $body = $this->pricingBody();
        $body['rates']['output_tokens'] = '40.12345'; // more than 4 decimals: refused, never rounded
        Http::fake(['*/pricing' => Http::response($body, 200)]);

        $this->actingAs(User::factory()->create())->postJson(route('generations.estimate'), $this->docsMaxRequest())->assertStatus(503);
        $this->assertFalse(Cache::has('pixelforge.pricing'));
    }

    public function test_pricing_is_cached_between_requests(): void
    {
        $this->fakePricing();
        $this->actingAs(User::factory()->create());

        $this->postJson(route('generations.estimate'), $this->docsMaxRequest())->assertOk();
        $this->postJson(route('generations.estimate'), $this->docsMaxRequest())->assertOk();

        $this->assertSame(1, $this->pricingRequestCount());
    }

    // --- validation (estimate and store share it) ---

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidInputs(): array
    {
        $ok = ['prompt' => 'a lighthouse', 'size' => '1024x1024', 'quality' => 'low', 'reference_image_urls' => []];

        return [
            'missing prompt' => [[...$ok, 'prompt' => ''], 'prompt'],
            'prompt of 4001 characters' => [[...$ok, 'prompt' => str_repeat('ğ', 4001)], 'prompt'],
            'five reference images' => [[...$ok, 'reference_image_urls' => array_fill(0, 5, 'https://example.com/a.png')], 'reference_image_urls'],
            'size not in the pricing table' => [[...$ok, 'size' => '2048x2048'], 'size'],
            'quality not in the pricing table' => [[...$ok, 'quality' => 'ultra'], 'quality'],
            'missing size' => [['prompt' => 'x', 'quality' => 'low'], 'size'],
            'not a url' => [[...$ok, 'reference_image_urls' => ['not a url']], 'reference_image_urls.0'],
            'ftp url' => [[...$ok, 'reference_image_urls' => ['ftp://example.com/a.png']], 'reference_image_urls.0'],
            'javascript url' => [[...$ok, 'reference_image_urls' => ['javascript:alert(1)']], 'reference_image_urls.0'],
            'references not a list' => [[...$ok, 'reference_image_urls' => 'https://example.com/a.png'], 'reference_image_urls'],
        ];
    }

    /** @param  array<string, mixed>  $input */
    #[DataProvider('invalidInputs')]
    public function test_invalid_input_is_rejected_by_estimate_and_store(array $input, string $field): void
    {
        $this->fakePricing();
        Queue::fake();
        $this->actingAs(User::factory()->create());

        $this->postJson(route('generations.estimate'), $input)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->postJson(route('generations.store'), $input)->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertSame(0, Generation::count());
        Queue::assertNothingPushed();
    }

    public function test_the_limits_themselves_are_accepted(): void
    {
        $this->fakePricing();
        $input = [
            'prompt' => str_repeat('ğ', 4000), // 4000 characters, 8000 bytes
            'size' => '1024x1536',
            'quality' => 'medium',
            'reference_image_urls' => array_fill(0, 4, 'http://example.com/a.png'),
        ];

        $this->actingAs(User::factory()->create())->postJson(route('generations.estimate'), $input)->assertOk();
    }

    public function test_sizes_and_qualities_come_from_the_pricing_table(): void
    {
        $body = $this->pricingBody();
        unset($body['tokens']['output']['high'], $body['tokens']['reasoning']['high']);
        $body['tokens']['output']['low']['1024x1536'] = 420;
        $body['tokens']['output']['medium'] = ['1024x1024' => 1100, '1536x1024' => 1650]; // no 1024x1536 for medium
        Http::fake(['*/pricing' => Http::response($body, 200)]);
        $this->actingAs(User::factory()->create());

        $this->get(route('generations.create'))->assertInertia(fn (Assert $page) => $page
            ->component('generations/Create')
            ->where('options.qualities', ['low', 'medium'])
            ->where('options.sizes', ['1024x1024', '1536x1024']) // only sizes priced for every quality
            ->where('pricing_error', null)
            ->where('limits', ['prompt_max' => 4000, 'references_max' => 4]));

        $input = ['prompt' => 'x', 'size' => '1024x1536', 'quality' => 'low'];
        $this->postJson(route('generations.estimate'), $input)->assertUnprocessable()->assertJsonValidationErrors('size');
        $this->postJson(route('generations.estimate'), [...$input, 'size' => '1024x1024', 'quality' => 'high'])->assertUnprocessable()->assertJsonValidationErrors('quality');
    }

    public function test_the_form_page_reports_missing_pricing_instead_of_options(): void
    {
        Http::fake(['*/pricing' => Http::response('down', 503)]);

        $this->actingAs(User::factory()->create())->get(route('generations.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('options.sizes', [])
                ->where('options.qualities', [])
                ->where('pricing_error', fn (string $m) => str_contains($m, 'Pricing is unavailable')));
    }

    // --- store ---

    public function test_store_creates_the_row_with_a_server_side_estimate_and_dispatches_the_job(): void
    {
        $this->fakePricing();
        Queue::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('generations.store'), [
            ...$this->docsMaxRequest(),
            // A forged estimate from the browser must be ignored.
            'estimate_min_pico' => 1, 'estimate_max_pico' => 2, 'status' => 'succeeded', 'user_id' => 999,
        ]);

        $response->assertRedirect(route('generations.index'));
        $row = Generation::sole();
        $this->assertSame($user->id, $row->user_id);
        $this->assertSame('queued', $row->status);
        $this->assertNull($row->pixelforge_id);
        $this->assertSame(str_repeat('a', 400), $row->prompt);
        $this->assertSame(['https://example.com/a.png', 'https://example.org/b.jpg'], $row->reference_image_urls);
        $this->assertSame(357_781_250_000, $row->estimate_min_pico);
        $this->assertSame(467_343_750_000, $row->estimate_max_pico); // $0.46734375
        Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->generationId === $row->id);
        Queue::assertPushedTimes(SubmitGeneration::class, 1);
    }

    public function test_reference_urls_sent_as_an_object_are_stored_as_a_plain_list(): void
    {
        $this->fakePricing();
        Queue::fake();

        $this->actingAs(User::factory()->create())->post(route('generations.store'), [
            ...$this->docsMaxRequest(),
            'reference_image_urls' => ['x' => 'https://example.com/a.png', 'y' => 'https://example.org/b.jpg'],
        ])->assertRedirect();

        // A JSON object would reach Pixelforge as an object, not as the array its docs ask for.
        $this->assertSame(['https://example.com/a.png', 'https://example.org/b.jpg'], Generation::sole()->reference_image_urls);
    }

    public function test_the_row_id_is_the_submission_id_which_is_also_the_idempotency_key(): void
    {
        $this->fakePricing();
        Queue::fake();
        $input = $this->docsMaxRequest();

        $this->actingAs(User::factory()->create())->post(route('generations.store'), $input)->assertRedirect();

        $row = Generation::sole();
        $this->assertSame($input['submission_id'], $row->id);
        Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->generationId === $input['submission_id']);
    }

    public function test_the_same_submission_posted_twice_makes_one_row_and_one_job(): void
    {
        $this->fakePricing();
        config(['queue.default' => 'database']);
        $input = $this->docsMaxRequest();
        $this->actingAs(User::factory()->create());

        $this->post(route('generations.store'), $input)->assertRedirect(route('generations.index'));
        $this->post(route('generations.store'), $input)->assertRedirect(route('generations.index')); // a double click, a retry
        $this->post(route('generations.store'), [...$input, 'prompt' => 'a different prompt'])->assertRedirect(route('generations.index'));

        $this->assertSame(1, Generation::count());
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(str_repeat('a', 400), Generation::sole()->prompt); // the first post won
    }

    public function test_another_users_submission_id_changes_nothing_and_looks_like_a_repeat(): void
    {
        $this->fakePricing();
        Queue::fake();
        [$owner, $intruder] = [User::factory()->create(), User::factory()->create()];
        $input = $this->docsMaxRequest();
        $this->actingAs($owner)->post(route('generations.store'), $input)->assertRedirect();
        $before = Generation::sole()->getAttributes();

        $response = $this->actingAs($intruder)->post(route('generations.store'), [...$input, 'prompt' => 'hijack']);

        $response->assertRedirect(route('generations.index'))->assertSessionHasNoErrors();
        $this->assertSame(1, Generation::count());
        $this->assertSame($before, Generation::sole()->getAttributes());
        $this->assertSame($owner->id, Generation::sole()->user_id);
        Queue::assertPushedTimes(SubmitGeneration::class, 1);
    }

    public function test_store_needs_a_valid_submission_id_but_estimate_does_not(): void
    {
        $this->fakePricing();
        Queue::fake();
        $input = $this->docsMaxRequest();
        unset($input['submission_id']);
        $this->actingAs(User::factory()->create());

        $this->postJson(route('generations.store'), $input)->assertUnprocessable()->assertJsonValidationErrors('submission_id');
        $this->postJson(route('generations.store'), [...$input, 'submission_id' => 'not-a-uuid'])->assertUnprocessable()->assertJsonValidationErrors('submission_id');
        $this->postJson(route('generations.estimate'), $input)->assertOk();

        $this->assertSame(0, Generation::count());
        Queue::assertNothingPushed();
    }

    public function test_the_form_page_does_not_hand_out_a_submission_id_the_browser_makes_its_own(): void
    {
        $this->fakePricing();
        $this->actingAs(User::factory()->create());

        $this->get(route('generations.create'))->assertInertia(fn (Assert $page) => $page->missing('submission_id'));
    }

    public function test_a_repeated_submission_flashes_an_explanation_and_a_new_id_makes_a_new_row(): void
    {
        $this->fakePricing();
        Queue::fake();
        $input = $this->docsMaxRequest();
        $this->actingAs(User::factory()->create());
        $message = 'That form was already submitted. Open Generate again for a new image.';

        $first = $this->post(route('generations.store'), $input);
        $first->assertRedirect(route('generations.index'));
        $this->assertStringNotContainsString('already submitted', json_encode(session('inertia.flash_data')) ?: '');

        $second = $this->post(route('generations.store'), $input);
        $second->assertRedirect(route('generations.index'));
        $this->assertSame(['type' => 'info', 'message' => $message], session('inertia.flash_data')['toast'] ?? null);
        $this->assertSame(1, Generation::count());

        $this->post(route('generations.store'), [...$input, 'submission_id' => (string) Str::uuid()])->assertRedirect(route('generations.index'));
        $this->assertSame(2, Generation::count());
        Queue::assertPushedTimes(SubmitGeneration::class, 2);
    }

    public function test_pricing_unavailable_is_not_reported_a_second_time_as_an_error(): void
    {
        Log::spy();

        app(ExceptionHandler::class)->report(new PricingUnavailableException('down'));

        Log::shouldNotHaveReceived('error');
    }

    public function test_store_without_pricing_creates_nothing(): void
    {
        Http::fake(['*/pricing' => Http::response('down', 503)]);
        Queue::fake();

        $this->actingAs(User::factory()->create())->post(route('generations.store'), $this->docsMaxRequest())
            ->assertSessionHasErrors('pricing');

        $this->assertSame(0, Generation::count());
        Queue::assertNothingPushed();
    }

    public function test_a_failure_right_after_the_insert_leaves_neither_row_nor_job(): void
    {
        $this->fakePricing();
        config(['queue.default' => 'database']);
        $this->withoutExceptionHandling();
        // Fires after the INSERT statement ran, inside the store's transaction.
        DB::listen(function (QueryExecuted $query): void {
            if (str_contains($query->sql, 'insert into "generations"')) {
                throw new \RuntimeException('boom after the insert');
            }
        });

        try {
            $this->actingAs(User::factory()->create())->post(route('generations.store'), $this->docsMaxRequest());
            $this->fail('the store should have thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom after the insert', $e->getMessage());
        }

        $this->assertSame(0, Generation::count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_the_job_is_sent_only_after_commit_and_never_if_an_outer_transaction_rolls_back(): void
    {
        $this->fakePricing();
        config(['queue.default' => 'database']); // Queue::fake() does not model afterCommit, the real queue does
        $this->actingAs(User::factory()->create());

        // Rolled back: no row and no job (before the commit the job is only waiting).
        DB::beginTransaction();
        $this->post(route('generations.store'), $this->docsMaxRequest())->assertRedirect();
        $this->assertSame(0, DB::table('jobs')->count());
        DB::rollBack();
        $this->assertSame(0, Generation::count());
        $this->assertSame(0, DB::table('jobs')->count());

        // Committed: the job appears only when the outer transaction commits.
        DB::beginTransaction();
        $this->post(route('generations.store'), $this->docsMaxRequest())->assertRedirect();
        $this->assertSame(0, DB::table('jobs')->count());
        DB::commit();
        $this->assertSame(1, Generation::count());
        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_with_the_database_queue_a_committed_store_leaves_one_row_and_one_job(): void
    {
        $this->fakePricing();
        config(['queue.default' => 'database']);

        $this->actingAs(User::factory()->create())->post(route('generations.store'), $this->docsMaxRequest())->assertRedirect();

        $this->assertSame(1, Generation::count());
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertStringContainsString(Generation::sole()->id, (string) DB::table('jobs')->value('payload'));
    }

    public function test_store_needs_login(): void
    {
        Bus::fake();

        $this->post(route('generations.store'), $this->docsMaxRequest())->assertRedirect(route('login'));
        Bus::assertNothingDispatched();
        $this->assertSame(0, Generation::count());
    }

    // --- history, pending, show ---

    public function test_history_lists_only_my_generations_newest_first(): void
    {
        [$me, $other] = [User::factory()->create(), User::factory()->create()];
        $old = Generation::factory()->for($me)->create(['created_at' => now()->subHour()]);
        $new = Generation::factory()->for($me)->create(['created_at' => now()]);
        $theirs = Generation::factory()->for($other)->create();

        $this->actingAs($me)->get(route('generations.index'))->assertInertia(fn (Assert $page) => $page
            ->component('generations/Index')
            ->has('generations.data', 2)
            ->where('generations.data.0.id', $new->id)
            ->where('generations.data.1.id', $old->id));

        $this->assertNotContains($theirs->id, collect($this->actingAs($me)->get(route('generations.index'))->viewData('page')['props']['generations']['data'])->pluck('id')->all());
    }

    public function test_pending_lists_only_my_non_final_rows(): void
    {
        [$me, $other] = [User::factory()->create(), User::factory()->create()];
        $queued = Generation::factory()->for($me)->create();
        $processing = Generation::factory()->for($me)->create(['status' => 'processing']);
        Generation::factory()->for($me)->create(['status' => 'succeeded']);
        Generation::factory()->for($me)->create(['status' => 'failed']);
        Generation::factory()->for($other)->create();

        $ids = collect($this->actingAs($me)->getJson(route('generations.pending'))->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame(collect([$queued->id, $processing->id])->sort()->values()->all(), $ids);
    }

    public function test_another_users_generation_is_a_404_like_one_that_does_not_exist(): void
    {
        [$me, $other] = [User::factory()->create(), User::factory()->create()];
        $theirs = Generation::factory()->for($other)->create();
        $this->actingAs($me);

        $this->getJson(route('generations.show', $theirs->id))->assertNotFound();
        $this->getJson(route('generations.show', '00000000-0000-4000-8000-000000000000'))->assertNotFound();
        $this->getJson('/generations/not-a-uuid')->assertNotFound();
        $this->getJson(route('generations.show', Generation::factory()->for($me)->create()->id))->assertOk();
    }

    public function test_guests_cannot_reach_history_pending_or_show(): void
    {
        $generation = Generation::factory()->create();

        $this->get(route('generations.index'))->assertRedirect(route('login'));
        $this->get(route('generations.create'))->assertRedirect(route('login'));
        $this->getJson(route('generations.pending'))->assertUnauthorized();
        $this->getJson(route('generations.show', $generation->id))->assertUnauthorized();
    }

    // --- the resource ---

    private function succeededRow(User $user, ?CarbonInterface $expires): Generation
    {
        $generation = Generation::factory()->for($user)->create([
            'status' => 'succeeded',
            'image_url' => 'https://pixelforge.promptedit.dev/images/x.png?signature=abc',
            'image_url_expires_at' => $expires,
            'completed_at' => now(),
            'estimate_min_pico' => 100_000_000_000,
            'estimate_max_pico' => 467_343_750_000,
        ]);
        // The docs' succeeded example: provider cost $0.305510, user price $0.3818875.
        Charge::query()->insertOrIgnore([
            'generation_id' => $generation->id, 'user_id' => $user->id,
            'provider_cost_pico' => 305_510_000_000, 'user_price_pico' => 381_887_500_000, 'created_at' => now(),
        ]);

        return $generation;
    }

    public function test_the_image_link_is_shown_only_while_it_has_not_expired(): void
    {
        $user = User::factory()->create();
        $live = $this->succeededRow($user, now()->addHour());
        $dead = $this->succeededRow($user, now()->subMinute());
        $unknown = $this->succeededRow($user, null);
        $this->actingAs($user);

        $live = $this->getJson(route('generations.show', $live->id))->json('data');
        $dead = $this->getJson(route('generations.show', $dead->id))->json('data');
        $unknown = $this->getJson(route('generations.show', $unknown->id))->json('data');

        $this->assertStringStartsWith('https://pixelforge.promptedit.dev/images/', $live['image_url']);
        $this->assertFalse($live['image_expired']);
        $this->assertNull($dead['image_url']);
        $this->assertTrue($dead['image_expired']);
        $this->assertNull($unknown['image_url']);
        $this->assertTrue($unknown['image_expired']);
        $this->assertStringNotContainsString('signature', (string) json_encode($dead));
    }

    public function test_only_https_image_links_are_shown(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        foreach (['http://pixelforge.promptedit.dev/images/x.png', 'javascript:alert(1)', 'data:image/png;base64,AAAA', '//evil.example/x.png'] as $url) {
            $row = $this->succeededRow($user, now()->addHour());
            DB::table('generations')->where('id', $row->id)->update(['image_url' => $url]);

            $data = $this->getJson(route('generations.show', $row->id))->json('data');

            $this->assertNull($data['image_url'], $url);
            $this->assertTrue($data['image_expired'], $url);
        }
    }

    public function test_money_fields_are_strings_and_provider_cost_never_appears(): void
    {
        $user = User::factory()->create();
        $generation = $this->succeededRow($user, now()->addHour());
        $this->actingAs($user);

        $response = $this->getJson(route('generations.show', $generation->id))->assertOk();
        $data = $response->json('data');

        $this->assertSame('$0.3818875', $data['price']);
        $this->assertSame(['min' => '$0.10', 'max' => '$0.46734375'], $data['estimate']);
        $this->assertIsString($data['price']);
        $this->assertSame(['id', 'prompt', 'size', 'quality', 'reference_image_urls', 'status', 'created_at', 'completed_at', 'estimate', 'price', 'failure_message', 'image_url', 'image_expired'], array_keys($data));

        // Every place the data can reach the browser: JSON, pending, and the Inertia history props.
        $everything = $response->getContent().$this->getJson(route('generations.pending'))->getContent()
            .json_encode($this->get(route('generations.index'))->viewData('page')['props']);
        foreach (['0.30551', '305510', 'provider', 'pico', 'pixelforge_id', 'gen_'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $everything);
        }
    }

    public function test_price_only_for_succeeded_and_failure_message_only_for_failed(): void
    {
        $user = User::factory()->create();
        $failed = Generation::factory()->for($user)->create(['status' => 'failed', 'failure_code' => 'internal_error', 'failure_message' => 'Please retry.']);
        $queued = Generation::factory()->for($user)->create(['failure_message' => 'stale text']);
        // A charge on a row that is not succeeded must still not show a price.
        Charge::query()->insertOrIgnore(['generation_id' => $queued->id, 'user_id' => $user->id, 'provider_cost_pico' => 1, 'user_price_pico' => 1, 'created_at' => now()]);
        $this->actingAs($user);

        $failedJson = $this->getJson(route('generations.show', $failed->id))->json('data');
        $queuedJson = $this->getJson(route('generations.show', $queued->id))->json('data');

        $this->assertSame('Please retry.', $failedJson['failure_message']);
        $this->assertNull($failedJson['price']);
        $this->assertNull($queuedJson['failure_message']);
        $this->assertNull($queuedJson['price']);
        $this->assertFalse($failedJson['image_expired']);
    }
}
