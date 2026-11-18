<?php

namespace App\Admin;

use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use App\Support\InvalidMoneyException;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The admin report: what we charged and what it cost us, per UTC day and per user, next to what
 * Pixelforge says it billed (GET /v1/usage) for the same days.
 *
 * Everything is added up in SQL and in integer picodollars. The only float-free path from the
 * database is digits-only strings turned into ints; money is formatted to dollars at the very end.
 * A generation belongs to the UTC day it COMPLETED (docs), so we group by completed_at, not created_at.
 */
class UsageReport
{
    private const CACHE_SECONDS = 60;

    public function __construct(private readonly PixelforgeClient $client) {}

    /**
     * @return array{
     *     from: string,
     *     to: string,
     *     days: list<array<string, mixed>>,
     *     totals: array<string, mixed>,
     *     pixelforge_error: string|null,
     * }
     */
    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ours = $this->ourChargesByDay($from, $to);
        $open = $this->openRowsByDay($from, $to);
        $theirs = $this->pixelforgeByDay($from, $to);

        // Every day that has something on either side. Pixelforge also lists empty days; those are skipped.
        $dates = array_keys($ours + $open + array_filter($theirs['days'] ?? [], fn (array $d) => $d['generations'] > 0 || $d['cost'] > 0));
        rsort($dates);

        $days = [];
        foreach ($dates as $date) {
            $days[] = $this->day($date, $ours[$date] ?? [], $open[$date] ?? ['pending' => 0, 'unknown' => 0], $theirs['days'][$date] ?? null, $theirs['days'] !== null);
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => $days,
            'totals' => $this->totals($ours, $open, $theirs['days']),
            'pixelforge_error' => $theirs['error'],
        ];
    }

    /**
     * @return array<string, list<array{user_id: int|null, label: string, charges: int, provider: int, charged: int}>>
     *                                                                                                                 keyed by UTC day (Y-m-d)
     */
    private function ourChargesByDay(CarbonImmutable $from, CarbonImmutable $to): array
    {
        // completed_at is timestamptz: AT TIME ZONE 'UTC' gives the UTC wall time whatever the session timezone is.
        $rows = DB::table('charges as c')
            ->join('generations as g', 'g.id', '=', 'c.generation_id')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->where('g.completed_at', '>=', $from->startOfDay()->toIso8601String())
            ->where('g.completed_at', '<', $to->addDay()->startOfDay()->toIso8601String())
            ->selectRaw("(g.completed_at at time zone 'UTC')::date as day, c.user_id, u.name as user_name, u.email as user_email, count(*) as charges, sum(c.provider_cost_pico) as provider, sum(c.user_price_pico) as charged")
            ->groupByRaw('1, c.user_id, u.name, u.email')
            ->orderByRaw('1, c.user_id')
            ->get();

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[(string) $row->day][] = [
                'user_id' => $row->user_id === null ? null : (int) $row->user_id,
                'label' => $row->user_id === null ? 'Deleted account' : "{$row->user_name} ({$row->user_email})",
                'charges' => (int) $row->charges,
                'provider' => $this->pico($row->provider),
                'charged' => $this->pico($row->charged),
            ];
        }

        return $byDay;
    }

    /**
     * Rows that explain a gap: still queued/processing, or still unfinished after an attempt with an
     * unknown outcome (Pixelforge may have created them). A finished row is no gap any more. They have
     * no completion day, so they are counted on the day they were created.
     *
     * @return array<string, array{pending: int, unknown: int}>
     */
    private function openRowsByDay(CarbonImmutable $from, CarbonImmutable $to): array
    {
        // created_at is a plain timestamp that the app writes in UTC, so ::date is already the UTC day.
        $rows = DB::table('generations')
            ->where('created_at', '>=', $from->startOfDay()->toDateTimeString())
            ->where('created_at', '<', $to->addDay()->startOfDay()->toDateTimeString())
            ->selectRaw('created_at::date as day, count(*) filter (where status in (?, ?)) as pending, count(*) filter (where unknown_outcome_at is not null and status in (?, ?)) as unknown', [Generation::QUEUED, Generation::PROCESSING, Generation::QUEUED, Generation::PROCESSING])
            ->groupByRaw('1')
            ->get();

        $byDay = [];
        foreach ($rows as $row) {
            if ((int) $row->pending > 0 || (int) $row->unknown > 0) {
                $byDay[(string) $row->day] = ['pending' => (int) $row->pending, 'unknown' => (int) $row->unknown];
            }
        }

        return $byDay;
    }

    /**
     * Pixelforge's own numbers for the same days, cached for a minute. Nothing usable: `days` is null
     * and the page says so, the rest of the report is still shown.
     *
     * @return array{days: array<string, array{generations: int, cost: int}>|null, error: string|null}
     */
    private function pixelforgeByDay(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $key = "pixelforge.usage.{$from->toDateString()}.{$to->toDateString()}";

        try {
            // Only an answer we could read is cached: the closure parses before it returns.
            $raw = Cache::remember($key, self::CACHE_SECONDS, function () use ($from, $to): array {
                $data = $this->client->usage($from->toDateString(), $to->toDateString());
                $this->parseUsage($data);

                return $data;
            });

            return ['days' => $this->parseUsage($raw), 'error' => null];
        } catch (PixelforgeException|InvalidMoneyException|RuntimeException $e) {
            Log::warning('Admin report: Pixelforge usage is unavailable: '.$e->getMessage());

            return ['days' => null, 'error' => 'Pixelforge usage unavailable'];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, array{generations: int, cost: int}>
     */
    private function parseUsage(array $data): array
    {
        if (! is_array($data['data'] ?? null)) {
            throw new RuntimeException('Usage answer has no "data" list');
        }

        $days = [];
        foreach ($data['data'] as $day) {
            if (! is_array($day) || ! is_string($day['date'] ?? null) || ! is_int($day['generations'] ?? null) || ! is_string($day['cost_usd'] ?? null)) {
                throw new RuntimeException('Usage day is malformed');
            }

            $days[$day['date']] = ['generations' => $day['generations'], 'cost' => Money::parseUsd($day['cost_usd'])];
        }

        return $days;
    }

    /**
     * @param  list<array{user_id: int|null, label: string, charges: int, provider: int, charged: int}>  $users
     * @param  array{pending: int, unknown: int}  $open
     * @param  array{generations: int, cost: int}|null  $theirs
     * @return array<string, mixed>
     */
    private function day(string $date, array $users, array $open, ?array $theirs, bool $pixelforgeKnown): array
    {
        // Biggest charge first; the deleted account (null id) always last.
        usort($users, fn (array $a, array $b) => [$a['user_id'] === null, $b['charged']] <=> [$b['user_id'] === null, $a['charged']]);

        $provider = $this->sum(array_column($users, 'provider'));
        $charged = $this->sum(array_column($users, 'charged'));
        $theirCost = $theirs['cost'] ?? 0;

        return [
            'date' => $date,
            'users' => array_map(fn (array $user) => [
                'user_id' => $user['user_id'],
                'label' => $user['label'],
                'charges' => $user['charges'],
                'provider_cost' => Money::formatUsd($user['provider']),
                'charged' => Money::formatUsd($user['charged']),
                'profit' => Money::formatUsd($user['charged'] - $user['provider']),
            ], $users),
            'ours' => [
                'charges' => array_sum(array_column($users, 'charges')),
                'provider_cost' => Money::formatUsd($provider),
                'charged' => Money::formatUsd($charged),
                'profit' => Money::formatUsd($charged - $provider),
            ],
            'pixelforge' => $pixelforgeKnown ? [
                'generations' => $theirs['generations'] ?? 0,
                'cost' => Money::formatUsd($theirCost),
            ] : null,
            // Pixelforge minus ours: positive means Pixelforge billed more than we recorded.
            'difference' => $pixelforgeKnown ? Money::formatUsd($theirCost - $provider) : null,
            'pending' => $open['pending'],
            'unknown_outcome' => $open['unknown'],
        ];
    }

    /**
     * @param  array<string, list<array{user_id: int|null, label: string, charges: int, provider: int, charged: int}>>  $ours
     * @param  array<string, array{pending: int, unknown: int}>  $open
     * @param  array<string, array{generations: int, cost: int}>|null  $theirs
     * @return array<string, mixed>
     */
    private function totals(array $ours, array $open, ?array $theirs): array
    {
        $rows = [];
        foreach ($ours as $dayRows) {
            foreach ($dayRows as $row) {
                $rows[] = $row;
            }
        }

        $provider = $this->sum(array_column($rows, 'provider'));
        $charged = $this->sum(array_column($rows, 'charged'));
        $theirCost = $theirs === null ? 0 : $this->sum(array_column($theirs, 'cost'));

        return [
            'ours' => [
                'charges' => array_sum(array_column($rows, 'charges')),
                'provider_cost' => Money::formatUsd($provider),
                'charged' => Money::formatUsd($charged),
                'profit' => Money::formatUsd($charged - $provider),
            ],
            'pixelforge' => $theirs === null ? null : [
                'generations' => array_sum(array_column($theirs, 'generations')),
                'cost' => Money::formatUsd($theirCost),
            ],
            'difference' => $theirs === null ? null : Money::formatUsd($theirCost - $provider),
            'pending' => array_sum(array_column($open, 'pending')),
            'unknown_outcome' => array_sum(array_column($open, 'unknown')),
        ];
    }

    /**
     * Adds picodollar amounts with the overflow guard of Money::add (never array_sum on money).
     *
     * @param  array<int, int>  $amounts
     */
    private function sum(array $amounts): int
    {
        $total = 0;

        foreach ($amounts as $amount) {
            $total = Money::add($total, $amount);
        }

        return $total;
    }

    /** SUM(bigint) comes back from Postgres as a digits-only string (numeric). Never a float. Throws InvalidMoneyException otherwise. */
    private function pico(mixed $value): int
    {
        // parseDecimal: digits only, no decimals, and at most 18 digits so it fits PHP's int.
        return Money::parseDecimal((string) $value, 0, 0);
    }
}
