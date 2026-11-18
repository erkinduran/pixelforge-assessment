<?php

namespace Tests\Unit\Pixelforge;

use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PixelforgeClientTest extends TestCase
{
    /** @return array<string, mixed> */
    private function generation(string $status = 'queued'): array
    {
        return ['id' => 'gen_TEST', 'object' => 'generation', 'status' => $status, 'metadata' => ['local_id' => 'abc']];
    }

    public function test_create_generation_sends_auth_idempotency_key_and_exact_body(): void
    {
        Http::fake(['*/generations' => Http::response($this->generation(), 202)]);

        $body = ['prompt' => 'a lighthouse', 'size' => '1024x1024', 'quality' => 'low', 'metadata' => ['local_id' => 'abc']];
        $result = (new PixelforgeClient)->createGeneration($body, 'key-1');

        $this->assertSame('gen_TEST', $result['generation']['id']);
        $this->assertFalse($result['replayed']);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($body) {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/generations')
                && $request->hasHeader('Authorization', 'Bearer test-key') // the forced dummy key from phpunit.xml
                && $request->header('Idempotency-Key') === ['key-1']
                && $request->body() === json_encode($body) // the bytes on the wire
                && $request->data() === $body;
        });
    }

    public function test_replay_header_is_detected(): void
    {
        Http::fake(['*/generations' => Http::response($this->generation('processing'), 202, ['Idempotent-Replayed' => 'true'])]);

        $result = (new PixelforgeClient)->createGeneration(['prompt' => 'x'], 'key-1');

        $this->assertTrue($result['replayed']);
        $this->assertSame('processing', $result['generation']['status']);
    }

    public function test_pricing_uses_short_timeouts_and_create_keeps_the_long_ones(): void
    {
        $seen = [];
        Http::fake(function (Request $request, array $options) use (&$seen) {
            $seen[] = [$request->method(), $options['timeout'] ?? null, $options['connect_timeout'] ?? null];

            return Http::response($this->generation(), 202);
        });

        $client = new PixelforgeClient;
        $client->pricing();
        $client->createGeneration(['prompt' => 'x'], 'key-1');

        // A Pixelforge outage must not hold a PHP worker for the 15 s a create call may take.
        $this->assertSame([['GET', 3, 2], ['POST', 15, 5]], $seen);
    }

    public function test_a_pricing_timeout_is_a_pixelforge_exception_with_no_status(): void
    {
        Http::fake(['*/pricing' => fn () => throw new ConnectionException('cURL error 28: timed out')]);

        try {
            (new PixelforgeClient)->pricing();
            $this->fail('should have thrown');
        } catch (PixelforgeException $e) {
            $this->assertNull($e->status);
        }
    }

    public function test_get_generation_requests_the_id_with_auth(): void
    {
        Http::fake(['*/generations/gen_TEST' => Http::response($this->generation('succeeded'), 200)]);

        $generation = (new PixelforgeClient)->getGeneration('gen_TEST');

        $this->assertSame('succeeded', $generation['status']);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_ends_with($request->url(), '/generations/gen_TEST')
            && $request->hasHeader('Authorization'));
    }

    public function test_pricing_returns_decoded_body(): void
    {
        Http::fake(['*/pricing' => Http::response(['object' => 'pricing', 'rates' => ['output_tokens' => '40.00']], 200)]);

        $pricing = (new PixelforgeClient)->pricing();

        $this->assertSame('40.00', $pricing['rates']['output_tokens']);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_ends_with($request->url(), '/pricing')
            && ! $request->hasHeader('Authorization'));
    }

    /**
     * One row per code in the docs "Errors" table, plus the rows that are our own decisions.
     *
     * @return array<string, array{int, string|null, string|null, array<string, string>, string, int|null, bool}>
     */
    public static function errorCases(): array
    {
        return [
            // docs table
            'invalid_api_key' => [401, 'invalid_api_key', 'authentication_error', [], 'fail', null, false],
            'validation_failed' => [422, 'validation_failed', 'invalid_request_error', [], 'fail', null, false],
            'content_policy_violation' => [400, 'content_policy_violation', 'invalid_request_error', [], 'fail', null, false],
            'not_found' => [404, 'not_found', 'invalid_request_error', [], 'fail', null, false],
            'idempotency_key_reused (a generation already exists)' => [409, 'idempotency_key_reused', 'idempotency_error', [], 'fail', null, true],
            'rate_limit_exceeded with Retry-After' => [429, 'rate_limit_exceeded', 'rate_limit_error', ['Retry-After' => '17'], 'retry', 17, false],
            'rate_limit_exceeded without Retry-After' => [429, 'rate_limit_exceeded', 'rate_limit_error', [], 'retry', null, false],
            'concurrency_limit_exceeded' => [429, 'concurrency_limit_exceeded', 'rate_limit_error', ['Retry-After' => '5'], 'wait', 5, false],
            'daily_limit_exceeded' => [429, 'daily_limit_exceeded', 'rate_limit_error', [], 'fail', null, false],
            'overloaded' => [503, 'overloaded', 'api_error', ['Retry-After' => '2'], 'retry', 2, false],
            'date_range_too_long' => [422, 'date_range_too_long', 'invalid_request_error', [], 'fail', null, false],
            // our own decisions (not in the docs)
            'unknown 5xx code' => [500, 'something_new', 'api_error', [], 'retry', null, true],
            'unknown 5xx, body is not JSON' => [502, null, null, [], 'retry', null, true],
            '503 that is not overloaded (proxy)' => [503, null, null, [], 'retry', null, true],
            'Retry-After -1 is ignored' => [429, 'rate_limit_exceeded', 'rate_limit_error', ['Retry-After' => '-1'], 'retry', null, false],
            'Retry-After empty is ignored' => [429, 'rate_limit_exceeded', 'rate_limit_error', ['Retry-After' => ''], 'retry', null, false],
            'Retry-After 25 digits is capped at 3600' => [429, 'rate_limit_exceeded', 'rate_limit_error', ['Retry-After' => '9999999999999999999999999'], 'retry', 3600, false],
            'unknown 4xx code' => [403, 'forbidden_thing', 'invalid_request_error', [], 'fail', null, false],
            'unknown 4xx, body is not JSON' => [400, null, null, [], 'fail', null, false],
            'unknown 429 code' => [429, 'some_new_limit', 'rate_limit_error', ['Retry-After' => '3'], 'retry', 3, false],
            'unknown 429, body is not JSON' => [429, null, null, [], 'retry', null, false],
            'Retry-After that is not a number is ignored' => [429, 'rate_limit_exceeded', 'rate_limit_error', ['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT'], 'retry', null, false],
        ];
    }

    /** @param  array<string, string>  $headers */
    #[DataProvider('errorCases')]
    public function test_error_response_maps_to_exception_and_decision(
        int $status,
        ?string $code,
        ?string $type,
        array $headers,
        string $expectedDecision,
        ?int $expectedRetryAfter,
        bool $expectedUnknownOutcome,
    ): void {
        $body = $code === null
            ? '<html>Bad Gateway</html>'
            : ['error' => ['type' => $type, 'code' => $code, 'message' => 'Message from Pixelforge.']];
        Http::fake(['*/generations' => Http::response($body, $status, $headers)]);

        try {
            (new PixelforgeClient)->createGeneration(['prompt' => 'x'], 'key-1');
            $this->fail('Expected a PixelforgeException.');
        } catch (PixelforgeException $e) {
            $this->assertSame($status, $e->status);
            $this->assertSame($code, $e->errorCode);
            $this->assertSame($type, $e->errorType);
            $this->assertSame($expectedRetryAfter, $e->retryAfter);
            $this->assertSame($expectedDecision, $e->decision()->value);
            $this->assertSame($expectedUnknownOutcome, $e->isUnknownOutcome());
            $this->assertSame($code === null ? "Pixelforge answered HTTP {$status}." : 'Message from Pixelforge.', $e->getMessage());
        }
    }

    public function test_get_and_pricing_failures_throw_too(): void
    {
        Http::fake([
            '*/generations/gen_NOPE' => Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'not_found', 'message' => 'No such generation.']], 404),
            '*/pricing' => Http::response('', 503, ['Retry-After' => '2']),
        ]);

        try {
            (new PixelforgeClient)->getGeneration('gen_NOPE');
            $this->fail('Expected a PixelforgeException.');
        } catch (PixelforgeException $e) {
            $this->assertSame('fail', $e->decision()->value);
        }

        try {
            (new PixelforgeClient)->pricing();
            $this->fail('Expected a PixelforgeException.');
        } catch (PixelforgeException $e) {
            $this->assertSame('retry', $e->decision()->value);
            $this->assertSame(2, $e->retryAfter);
        }
    }

    public function test_malformed_error_body_still_becomes_a_pixelforge_exception(): void
    {
        Http::fake(['*/generations' => Http::response(['error' => ['code' => 123, 'type' => ['x'], 'message' => false]], 500)]);

        try {
            (new PixelforgeClient)->createGeneration(['prompt' => 'x'], 'key-1');
            $this->fail('Expected a PixelforgeException.');
        } catch (PixelforgeException $e) {
            $this->assertSame(500, $e->status);
            $this->assertNull($e->errorCode);
            $this->assertNull($e->errorType);
            $this->assertSame('Pixelforge answered HTTP 500.', $e->getMessage());
            $this->assertSame('retry', $e->decision()->value);
        }
    }

    public function test_connection_exception_becomes_status_null_and_retry(): void
    {
        Http::fake(['*/generations' => fn () => throw new ConnectionException('cURL error 28: timed out')]);

        try {
            (new PixelforgeClient)->createGeneration(['prompt' => 'x'], 'key-1');
            $this->fail('Expected a PixelforgeException.');
        } catch (PixelforgeException $e) {
            $this->assertNull($e->status);
            $this->assertNull($e->errorCode);
            $this->assertNull($e->retryAfter);
            $this->assertSame('retry', $e->decision()->value);
            $this->assertTrue($e->isUnknownOutcome());
        }
    }

    public function test_success_with_non_json_body_is_a_retry(): void
    {
        Http::fake(['*/generations' => Http::response('not json', 202)]);

        try {
            (new PixelforgeClient)->createGeneration(['prompt' => 'x'], 'key-1');
            $this->fail('Expected a PixelforgeException.');
        } catch (PixelforgeException $e) {
            $this->assertNull($e->status);
            $this->assertSame('retry', $e->decision()->value);
            $this->assertTrue($e->isUnknownOutcome());
        }
    }

    public function test_client_does_not_retry_on_its_own(): void
    {
        Http::fake(['*/generations' => Http::response(['error' => ['code' => 'overloaded', 'type' => 'api_error', 'message' => 'x']], 503, ['Retry-After' => '2'])]);

        try {
            (new PixelforgeClient)->createGeneration(['prompt' => 'x'], 'key-1');
        } catch (PixelforgeException) {
        }

        Http::assertSentCount(1);
    }

    public function test_stray_requests_are_blocked_in_tests(): void
    {
        // No Http::fake() here: the real API must never be reached.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Attempted request');

        (new PixelforgeClient)->pricing();
    }
}
