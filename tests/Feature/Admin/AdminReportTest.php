<?php

namespace Tests\Feature\Admin;

use App\Admin\UsageReport;
use App\Models\Charge;
use App\Models\Generation;
use App\Models\User;
use App\Support\InvalidMoneyException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminReportTest extends TestCase
{
    use RefreshDatabase;

    /** A succeeded generation that completed at $completedAt, with its charge. */
    private function charge(?User $user, string $completedAt, int $provider, int $charged): Generation
    {
        $generation = Generation::factory()->create(['user_id' => $user?->id, 'status' => 'succeeded']);
        // Written raw: the model's datetime cast would drop a "+03" offset and keep the wall time.
        DB::table('generations')->where('id', $generation->id)->update(['completed_at' => $completedAt]);

        Charge::query()->insertOrIgnore([
            'generation_id' => $generation->id,
            'user_id' => $user?->id,
            'provider_cost_pico' => $provider,
            'user_price_pico' => $charged,
            'created_at' => now(),
        ]);

        return $generation;
    }

    /** @param  array<int, array{date: string, generations: int, cost_usd: string}>  $days */
    private function fakeUsage(array $days): void
    {
        $rows = array_map(fn (array $day) => [...$day, 'input_text_tokens' => 0, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0], $days);
        Http::fake(['*/usage*' => Http::response(['object' => 'usage', 'start_date' => '2026-11-01', 'end_date' => '2026-11-30', 'data' => $rows, 'total' => []], 200)]);
    }

    /** @return array<string, mixed> */
    private function report(?User $viewer = null, string $from = '2026-11-01', string $to = '2026-11-30'): array
    {
        $response = $this->actingAs($viewer ?? User::factory()->admin()->create())
            ->get(route('admin.report', ['from' => $from, 'to' => $to]))
            ->assertOk();

        return $response->viewData('page')['props']['report'];
    }

    // --- grouping and sums ---

    public function test_charges_are_grouped_by_the_utc_day_they_completed_at_the_second_boundary(): void
    {
        $user = User::factory()->create();
        $this->charge($user, '2026-11-17 23:59:59+00', 100, 125);
        $this->charge($user, '2026-11-18 00:00:00+00', 200, 250);
        $this->charge($user, '2026-11-18 23:59:59+00', 400, 500);
        $this->charge($user, '2026-11-19 00:00:00+00', 800, 1000);
        // 02:30 in +03:00 is 23:30 UTC on the 18th, not the 19th.
        $this->charge($user, '2026-11-19 02:30:00+03', 1600, 2000);
        $this->fakeUsage([]);

        $report = $this->report();

        $byDay = collect($report['days'])->keyBy('date');
        $this->assertSame(['2026-11-19', '2026-11-18', '2026-11-17'], collect($report['days'])->pluck('date')->all());
        $this->assertSame(1, $byDay['2026-11-17']['ours']['charges']);
        $this->assertSame(3, $byDay['2026-11-18']['ours']['charges']); // 00:00:00, 23:59:59 and the +03:00 one
        $this->assertSame(1, $byDay['2026-11-19']['ours']['charges']);
    }

    public function test_grouping_does_not_depend_on_the_database_session_timezone(): void
    {
        $user = User::factory()->create();
        $this->charge($user, '2026-11-18 23:30:00+00', 100, 125);
        $this->fakeUsage([]);
        DB::statement("SET TIME ZONE 'Pacific/Auckland'"); // +13:00: the same instant is the 19th there

        try {
            $report = $this->report();
        } finally {
            DB::statement("SET TIME ZONE 'UTC'");
        }

        $this->assertSame(['2026-11-18'], collect($report['days'])->pluck('date')->all());
    }

    public function test_sums_are_exact_beyond_what_a_float_can_hold_and_profit_is_charged_minus_cost(): void
    {
        $user = User::factory()->create();
        $big = 9_007_199_254_740_993; // 2^53 + 1: a float would round it
        $this->charge($user, '2026-11-18 10:00:00+00', $big, $big + 5);
        $this->charge($user, '2026-11-18 11:00:00+00', 1, 3);
        $this->fakeUsage([]);

        $day = $this->report()['days'][0];

        // 2^53 + 2 pico of cost, 2^53 + 9 charged: exact, 7 pico of profit.
        $this->assertSame('$9007.199254740994', $day['ours']['provider_cost']);
        $this->assertSame('$9007.199254741001', $day['ours']['charged']);
        $this->assertSame('$0.000000000007', $day['ours']['profit']);
        $this->assertIsString($day['ours']['profit']);
    }

    public function test_a_money_sum_that_does_not_fit_is_refused_not_rounded(): void
    {
        $user = User::factory()->create();
        // Two charges of 9e17 pico: the day's sum has 19 digits, one more than Money accepts.
        $this->charge($user, '2026-11-18 10:00:00+00', 900_000_000_000_000_000, 900_000_000_000_000_001);
        $this->charge($user, '2026-11-18 11:00:00+00', 900_000_000_000_000_000, 900_000_000_000_000_001);
        $this->fakeUsage([]);

        $this->expectException(InvalidMoneyException::class);

        app(UsageReport::class)->build(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'));
    }

    public function test_adding_up_users_cannot_overflow_php_ints_silently(): void
    {
        // Each user's own sum has 18 digits and is fine; ten of them are 9.9e18, more than PHP_INT_MAX.
        foreach (range(1, 10) as $i) {
            $this->charge(User::factory()->create(), '2026-11-18 10:00:00+00', 990_000_000_000_000_000, 990_000_000_000_000_001);
        }
        $this->fakeUsage([]);

        $this->expectException(InvalidMoneyException::class);

        app(UsageReport::class)->build(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'));
    }

    public function test_per_user_rows_a_day_subtotal_and_a_grand_total(): void
    {
        [$ann, $bob] = [User::factory()->create(['name' => 'Ann', 'email' => 'ann@example.test']), User::factory()->create(['name' => 'Bob', 'email' => 'bob@example.test'])];
        $this->charge($ann, '2026-11-18 10:00:00+00', 100_000_000_000, 125_000_000_000);
        $this->charge($ann, '2026-11-18 11:00:00+00', 100_000_000_000, 125_000_000_000);
        $this->charge($bob, '2026-11-18 12:00:00+00', 300_000_000_000, 375_000_000_000);
        $this->charge($bob, '2026-11-19 12:00:00+00', 100_000_000_000, 125_000_000_000);
        $this->fakeUsage([]);

        $report = $this->report();
        $day = collect($report['days'])->firstWhere('date', '2026-11-18');

        $this->assertSame(['Bob (bob@example.test)', 'Ann (ann@example.test)'], collect($day['users'])->pluck('label')->all()); // biggest first
        $annRow = collect($day['users'])->firstWhere('user_id', $ann->id);
        $this->assertSame(['charges' => 2, 'provider_cost' => '$0.20', 'charged' => '$0.25', 'profit' => '$0.05'], collect($annRow)->only(['charges', 'provider_cost', 'charged', 'profit'])->all());
        $this->assertSame(['charges' => 3, 'provider_cost' => '$0.50', 'charged' => '$0.625', 'profit' => '$0.125'], $day['ours']);
        $this->assertSame(['charges' => 4, 'provider_cost' => '$0.60', 'charged' => '$0.75', 'profit' => '$0.15'], $report['totals']['ours']);
    }

    public function test_charges_of_deleted_accounts_are_shown_as_deleted_account_and_stay_in_the_totals(): void
    {
        $user = User::factory()->create();
        $this->charge($user, '2026-11-18 10:00:00+00', 100, 125);
        $this->charge(null, '2026-11-18 11:00:00+00', 200, 250);
        $this->fakeUsage([]);

        $day = $this->report()['days'][0];

        $this->assertSame('Deleted account', collect($day['users'])->last()['label']);
        $this->assertNull(collect($day['users'])->last()['user_id']);
        $this->assertSame(2, $day['ours']['charges']);
    }

    // --- reconciliation ---

    public function test_the_local_acceptance_numbers_show_a_difference_of_exactly_14715000000_pico(): void
    {
        $user = User::factory()->create();
        // Four generations as in the dev data: provider 250,172,500,000 pico, charged 312,715,625,000.
        $this->charge($user, '2026-11-18 09:00:00+00', 60_000_000_000, 75_000_000_000);
        $this->charge($user, '2026-11-18 10:00:00+00', 80_000_000_000, 100_000_000_000);
        $this->charge($user, '2026-11-18 11:00:00+00', 50_172_500_000, 62_715_625_000);
        $this->charge($user, '2026-11-18 12:00:00+00', 60_000_000_000, 75_000_000_000);
        $this->fakeUsage([['date' => '2026-11-18', 'generations' => 5, 'cost_usd' => '0.264887500']]);

        $report = $this->report();
        $day = $report['days'][0];

        $this->assertSame('$0.2501725', $day['ours']['provider_cost']);   // 250,172,500,000 pico
        $this->assertSame('$0.312715625', $day['ours']['charged']);        // 312,715,625,000
        $this->assertSame('$0.062543125', $day['ours']['profit']);         // 62,543,125,000
        $this->assertSame(['generations' => 5, 'cost' => '$0.2648875'], $day['pixelforge']);
        $this->assertSame('$0.014715', $day['difference']);                // 14,715,000,000 pico: Pixelforge billed more
        $this->assertSame('$0.014715', $report['totals']['difference']);
        $this->assertSame(4, $day['ours']['charges']);
    }

    public function test_a_pixelforge_day_with_nothing_in_our_database_still_shows(): void
    {
        $user = User::factory()->create();
        $this->charge($user, '2026-11-18 10:00:00+00', 100_000_000_000, 125_000_000_000);
        $this->fakeUsage([
            ['date' => '2026-11-16', 'generations' => 2, 'cost_usd' => '0.080000000'],
            ['date' => '2026-11-17', 'generations' => 0, 'cost_usd' => '0.000000000'], // empty on both sides: not listed
            ['date' => '2026-11-18', 'generations' => 1, 'cost_usd' => '0.100000000'],
        ]);

        $report = $this->report();

        $this->assertSame(['2026-11-18', '2026-11-16'], collect($report['days'])->pluck('date')->all());
        $only = collect($report['days'])->firstWhere('date', '2026-11-16');
        $this->assertSame([], $only['users']);
        $this->assertSame('$0.00', $only['ours']['provider_cost']);
        $this->assertSame('$0.08', $only['difference']);
        $this->assertSame(['generations' => 3, 'cost' => '$0.18'], $report['totals']['pixelforge']);
        $this->assertSame('$0.08', $report['totals']['difference']);
    }

    public function test_a_day_we_have_and_pixelforge_does_not_shows_a_negative_difference(): void
    {
        $this->charge(User::factory()->create(), '2026-11-18 10:00:00+00', 100_000_000_000, 125_000_000_000);
        $this->fakeUsage([['date' => '2026-11-18', 'generations' => 0, 'cost_usd' => '0.000000000']]);

        $day = $this->report()['days'][0];

        $this->assertSame('-$0.10', $day['difference']);
    }

    public function test_rows_that_explain_gaps_are_counted_on_the_day_they_were_created(): void
    {
        $user = User::factory()->create();
        Generation::factory()->for($user)->create(['created_at' => '2026-11-18 08:00:00']); // queued
        Generation::factory()->for($user)->create(['created_at' => '2026-11-18 09:00:00', 'status' => 'processing', 'pixelforge_id' => 'gen_p']);
        Generation::factory()->for($user)->create(['created_at' => '2026-11-18 09:30:00', 'unknown_outcome_at' => now()]);
        Generation::factory()->for($user)->create(['created_at' => '2026-11-18 10:00:00', 'status' => 'failed']);   // final: not counted
        // An unknown outcome that later finished is no gap any more: not counted either.
        Generation::factory()->for($user)->create(['created_at' => '2026-11-18 10:30:00', 'status' => 'succeeded', 'unknown_outcome_at' => now()]);
        Generation::factory()->for($user)->create(['created_at' => '2026-11-18 10:40:00', 'status' => 'failed', 'unknown_outcome_at' => now()]);
        $this->fakeUsage([]);

        $report = $this->report();

        $day = collect($report['days'])->firstWhere('date', '2026-11-18');
        $this->assertSame(3, $day['pending']);          // queued + processing + the queued one with the unknown outcome
        $this->assertSame(1, $day['unknown_outcome']);
        $this->assertSame(['pending' => 3, 'unknown_outcome' => 1], ['pending' => $report['totals']['pending'], 'unknown_outcome' => $report['totals']['unknown_outcome']]);
    }

    public function test_unavailable_pixelforge_usage_is_said_clearly_and_our_numbers_still_show(): void
    {
        $this->charge(User::factory()->create(), '2026-11-18 10:00:00+00', 100, 125);

        foreach ([
            Http::response(['error' => ['code' => 'overloaded', 'message' => 'x']], 503),
            Http::response('<html>nope</html>', 200),
            Http::response(['object' => 'usage', 'data' => [['date' => '2026-11-18', 'generations' => 1, 'cost_usd' => '0.1234567890123']]], 200), // more than 12 decimals
            fn () => throw new ConnectionException('cURL error 28: timed out'),
        ] as $answer) {
            Cache::flush();
            Http::fake(['*/usage*' => $answer]);

            $report = $this->report();

            $this->assertSame('Pixelforge usage unavailable', $report['pixelforge_error']);
            $this->assertNull($report['days'][0]['pixelforge']);
            $this->assertNull($report['days'][0]['difference']);
            $this->assertNull($report['totals']['difference']);
            $this->assertSame(1, $report['days'][0]['ours']['charges']);
        }
    }

    public function test_pixelforge_usage_is_cached_for_a_minute_per_range_and_failures_are_not_cached(): void
    {
        $this->fakeUsage([]);
        $admin = User::factory()->admin()->create();

        $this->report($admin);
        $this->report($admin);
        $this->assertCount(1, Http::recorded(fn (Request $r) => str_contains($r->url(), '/usage')));

        $this->report($admin, '2026-11-02', '2026-11-30'); // another range: another request
        $this->assertCount(2, Http::recorded(fn (Request $r) => str_contains($r->url(), '/usage')));

        Carbon::setTestNow(now()->addSeconds(61));
        $this->report($admin);
        $this->assertCount(3, Http::recorded(fn (Request $r) => str_contains($r->url(), '/usage')));
    }

    public function test_the_usage_request_carries_the_range(): void
    {
        $this->fakeUsage([]);

        $this->report(null, '2026-11-05', '2026-11-18');

        Http::assertSent(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/usage')
                && $query === ['start_date' => '2026-11-05', 'end_date' => '2026-11-18']
                && $request->hasHeader('Authorization');
        });
    }

    // --- the range ---

    public function test_the_default_range_is_the_last_30_days_ending_today_utc(): void
    {
        Carbon::setTestNow('2026-11-18 23:30:00');
        $this->fakeUsage([]);

        $props = $this->actingAs(User::factory()->admin()->create())->get(route('admin.report'))->assertOk()->viewData('page')['props'];

        $this->assertSame(['from' => '2026-10-20', 'to' => '2026-11-18', 'max_days' => 92], $props['range']);
        $this->assertSame('2026-10-20', $props['report']['from']);
    }

    public function test_the_range_is_limited_to_92_days_and_must_be_in_order(): void
    {
        $this->fakeUsage([]);
        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('admin.report', ['from' => '2026-01-01', 'to' => '2026-04-02']))->assertOk(); // exactly 92 days
        $this->get(route('admin.report', ['from' => '2026-01-01', 'to' => '2026-04-03']))->assertSessionHasErrors('to');
        $this->get(route('admin.report', ['from' => '2026-11-10', 'to' => '2026-11-01']))->assertSessionHasErrors('to');
        $this->get(route('admin.report', ['from' => 'yesterday']))->assertSessionHasErrors('from');
    }

    // --- access ---

    public function test_only_admins_can_open_the_report(): void
    {
        $this->fakeUsage([]);

        $this->get(route('admin.report'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('admin.report'))->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get(route('admin.report'))->assertOk();
    }

    public function test_a_forbidden_request_never_asks_pixelforge_or_reads_money(): void
    {
        Http::fake();

        $this->actingAs(User::factory()->create())->get(route('admin.report'))->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_the_shared_auth_user_tells_the_sidebar_whether_to_show_the_link(): void
    {
        $this->fakeUsage([]);

        $admin = $this->actingAs(User::factory()->admin()->create())->get(route('dashboard'))->viewData('page')['props']['auth']['user'];
        $plain = $this->actingAs(User::factory()->create()->fresh())->get(route('dashboard'))->viewData('page')['props']['auth']['user'];

        $this->assertTrue($admin['is_admin']);
        $this->assertFalse($plain['is_admin']);
    }

    public function test_registration_and_profile_update_cannot_make_anyone_an_admin(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Mallory', 'email' => 'mallory@example.test', 'password' => 'password', 'password_confirmation' => 'password',
            'is_admin' => true,
        ]);
        $mallory = User::where('email', 'mallory@example.test')->firstOrFail();
        $this->assertFalse($mallory->is_admin);

        $this->actingAs($mallory)->patch(route('profile.update'), ['name' => 'Mallory', 'email' => 'mallory@example.test', 'is_admin' => true]);
        $this->assertFalse($mallory->fresh()->is_admin);

        $this->assertFalse((new User)->isFillable('is_admin'));
        $this->actingAs($mallory)->get(route('admin.report'))->assertForbidden();
    }

    // --- the command ---

    public function test_make_admin_sets_the_flag_and_reports_an_unknown_email(): void
    {
        $user = User::factory()->create(['email' => 'boss@example.test']);

        $this->artisan('app:make-admin', ['email' => 'boss@example.test'])->assertSuccessful();
        $this->assertTrue($user->fresh()->is_admin);

        $this->artisan('app:make-admin', ['email' => 'boss@example.test'])->assertSuccessful(); // again: fine
        $this->artisan('app:make-admin', ['email' => 'nobody@example.test'])->assertFailed();
        $this->assertSame(1, User::where('is_admin', true)->count());
    }

    // --- what the page may carry ---

    public function test_money_in_the_props_is_only_formatted_strings(): void
    {
        $this->charge(User::factory()->create(), '2026-11-18 10:00:00+00', 100_000_000_000, 125_000_000_000);
        $this->fakeUsage([['date' => '2026-11-18', 'generations' => 1, 'cost_usd' => '0.100000000']]);

        $props = $this->actingAs(User::factory()->admin()->create())->get(route('admin.report', ['from' => '2026-11-01', 'to' => '2026-11-30']))->viewData('page')['props'];
        $json = json_encode($props['report']);

        $this->assertStringNotContainsString('_pico', (string) $json);
        $this->assertStringNotContainsString('100000000000', (string) $json);
        $day = $props['report']['days'][0];
        foreach (['provider_cost', 'charged', 'profit'] as $key) {
            $this->assertIsString($day['ours'][$key]);
        }
        $this->assertIsString($day['difference']);
    }

    public function test_provider_cost_is_in_no_other_response(): void
    {
        $user = User::factory()->create();
        $generation = $this->charge($user, '2026-11-18 10:00:00+00', 305_510_000_000, 381_887_500_000);
        $this->actingAs($user);

        $everything = $this->getJson(route('generations.show', $generation->id))->getContent()
            .$this->getJson(route('generations.pending'))->getContent()
            .json_encode($this->get(route('generations.index'))->viewData('page')['props']);

        foreach (['0.30551', 'provider'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $everything);
        }
    }
}
