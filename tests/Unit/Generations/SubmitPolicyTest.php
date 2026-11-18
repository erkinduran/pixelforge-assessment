<?php

namespace Tests\Unit\Generations;

use App\Generations\SubmitPolicy;
use App\Generations\SubmitStep;
use App\Pixelforge\PixelforgeException;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The decision table of SubmitPolicy, one row per case. No database, no HTTP. */
class SubmitPolicyTest extends TestCase
{
    private function error(?int $status, ?string $code, ?int $retryAfter = null): PixelforgeException
    {
        return new PixelforgeException('boom', $status, $code, null, $retryAfter);
    }

    /**
     * @return array<string, array{PixelforgeException, int, bool, int, SubmitStep, int, string|null}>
     */
    public static function table(): array
    {
        $e = fn (?int $status, ?string $code, ?int $retryAfter = null) => new PixelforgeException('boom', $status, $code, null, $retryAfter);

        // [exception, attempts before, unknown outcome set, seconds waited, step, release seconds, failure code]
        return [
            // Wait: concurrency limit
            'concurrency, no header: release 5' => [$e(429, 'concurrency_limit_exceeded'), 0, false, 0, SubmitStep::Release, 5, null],
            'concurrency, Retry-After 7' => [$e(429, 'concurrency_limit_exceeded', 7), 0, false, 100, SubmitStep::Release, 7, null],
            'concurrency ignores attempts' => [$e(429, 'concurrency_limit_exceeded'), 99, false, 0, SubmitStep::Release, 5, null],
            'concurrency just inside budget' => [$e(429, 'concurrency_limit_exceeded'), 0, false, 599, SubmitStep::Release, 5, null],
            'concurrency budget used up: give up' => [$e(429, 'concurrency_limit_exceeded'), 0, false, 600, SubmitStep::GiveUp, 0, null],
            'concurrency budget used up, unknown earlier' => [$e(429, 'concurrency_limit_exceeded'), 0, true, 900, SubmitStep::GiveUp, 0, null],

            // Retry: backoff 2, 4, 8, 16, then the cap
            'overloaded, 1st failure: 2 s' => [$e(503, 'overloaded'), 0, false, 0, SubmitStep::Release, 2, null],
            'overloaded, 2nd failure: 4 s' => [$e(503, 'overloaded'), 1, false, 0, SubmitStep::Release, 4, null],
            'overloaded, 3rd failure: 8 s' => [$e(503, 'overloaded'), 2, false, 0, SubmitStep::Release, 8, null],
            'overloaded, 4th failure: 16 s' => [$e(503, 'overloaded'), 3, false, 0, SubmitStep::Release, 16, null],
            'overloaded, 5th failure: fail' => [$e(503, 'overloaded'), 4, false, 0, SubmitStep::Fail, 0, 'overloaded'],
            'overloaded, Retry-After beats backoff' => [$e(503, 'overloaded', 30), 0, false, 0, SubmitStep::Release, 30, null],
            'overloaded, backoff beats a short Retry-After' => [$e(503, 'overloaded', 2), 3, false, 0, SubmitStep::Release, 16, null],
            'rate limit, 1st: honours Retry-After' => [$e(429, 'rate_limit_exceeded', 12), 0, false, 0, SubmitStep::Release, 12, null],
            'rate limit at the cap: fail' => [$e(429, 'rate_limit_exceeded', 12), 4, false, 0, SubmitStep::Fail, 0, 'rate_limit_exceeded'],
            'unknown 429 code is retried' => [$e(429, 'something_new'), 0, false, 0, SubmitStep::Release, 2, null],
            'timeout (unknown outcome), 1st' => [$e(null, null), 0, true, 0, SubmitStep::Release, 2, null],
            'timeout at the cap: give up, never fail' => [$e(null, null), 4, true, 0, SubmitStep::GiveUp, 0, null],
            'unknown 5xx at the cap: give up, never fail' => [$e(502, null), 4, true, 0, SubmitStep::GiveUp, 0, null],
            'overloaded at the cap after an earlier timeout: give up' => [$e(503, 'overloaded'), 4, true, 0, SubmitStep::GiveUp, 0, null],

            // Fail: definite rejections
            'daily limit: fail at once' => [$e(429, 'daily_limit_exceeded'), 0, false, 0, SubmitStep::Fail, 0, 'daily_limit_exceeded'],
            'content policy: fail' => [$e(400, 'content_policy_violation'), 0, false, 0, SubmitStep::Fail, 0, 'content_policy_violation'],
            'validation: fail' => [$e(422, 'validation_failed'), 0, false, 0, SubmitStep::Fail, 0, 'validation_failed'],
            'bad key 401: fail' => [$e(401, 'invalid_api_key'), 0, false, 0, SubmitStep::Fail, 0, 'invalid_api_key'],
            '404: fail' => [$e(404, 'not_found'), 0, false, 0, SubmitStep::Fail, 0, 'not_found'],
            'other 4xx without code: fail' => [$e(418, null), 0, false, 0, SubmitStep::Fail, 0, 'submit_failed'],
            'definite rejection after an earlier timeout: leave queued' => [$e(400, 'content_policy_violation'), 0, true, 0, SubmitStep::GiveUp, 0, null],
            'daily limit after an earlier timeout: leave queued' => [$e(429, 'daily_limit_exceeded'), 0, true, 0, SubmitStep::GiveUp, 0, null],

            // 409: a generation already exists under the key
            '409: leave queued, never fail' => [$e(409, 'idempotency_key_reused'), 0, true, 0, SubmitStep::GiveUp, 0, null],
        ];
    }

    #[DataProvider('table')]
    public function test_decision_table(PixelforgeException $e, int $attempts, bool $unknown, int $waited, SubmitStep $step, int $seconds, ?string $failureCode): void
    {
        $now = Carbon::parse('2026-11-18 12:00:00');
        $unknownAt = $unknown ? $now->copy()->subMinute() : null;

        $action = (new SubmitPolicy)->next($e, $attempts, $unknownAt, $now->copy()->subSeconds($waited), $now);

        $this->assertSame($step, $action->step);
        $this->assertSame($seconds, $action->seconds);
        $this->assertSame($failureCode, $action->failureCode);

        if ($step === SubmitStep::Fail) {
            $this->assertNotEmpty($action->failureMessage);
        } else {
            $this->assertNull($action->failureMessage);
        }
    }

    public function test_failure_messages_are_readable_per_code(): void
    {
        $now = Carbon::parse('2026-11-18 12:00:00');
        $message = fn (PixelforgeException $e) => (new SubmitPolicy)->next($e, 0, null, $now, $now)->failureMessage;

        $this->assertStringContainsString('daily generation limit', $message($this->error(429, 'daily_limit_exceeded')));
        $this->assertStringContainsString('content policy', $message($this->error(400, 'content_policy_violation')));
        $this->assertStringContainsString('credentials', $message($this->error(401, 'invalid_api_key')));

        // Validation and unknown codes: one generic text, never the provider's own words.
        $generic = 'The request could not be processed. Please try again.';
        $this->assertSame($generic, $message($this->error(422, 'validation_failed')));
        $this->assertSame($generic, $message($this->error(418, null)));
        $this->assertSame($generic, $message($this->error(404, 'not_found')));
    }
}
