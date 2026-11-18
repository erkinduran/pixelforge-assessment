<?php

namespace App\Pixelforge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Every failed Pixelforge call ends up here. The client never retries; the caller asks
 * decision() what to do.
 */
class PixelforgeException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,      // HTTP status, null when there was no response
        public readonly ?string $errorCode = null, // docs `error.code`, e.g. "rate_limit_exceeded"
        public readonly ?string $errorType = null, // docs `error.type`, e.g. "rate_limit_error"
        public readonly ?int $retryAfter = null,   // seconds from the Retry-After header, null if absent
    ) {
        parent::__construct($message);
    }

    /** The server answered with a 4xx/5xx. */
    public static function fromResponse(Response $response): self
    {
        $status = $response->status();
        $retryAfter = $response->header('Retry-After');

        // A malformed error body (wrong types, not JSON) must still become a PixelforgeException.
        $message = $response->json('error.message');
        $code = $response->json('error.code');
        $type = $response->json('error.type');

        return new self(
            message: is_string($message) ? $message : "Pixelforge answered HTTP {$status}.",
            status: $status,
            errorCode: is_string($code) ? $code : null,
            errorType: is_string($type) ? $type : null,
            // Docs: Retry-After is a number of seconds. Anything else (an HTTP date, "-1", "") is
            // ignored. Capped at one hour so a bad header cannot park a job for days.
            retryAfter: ctype_digit($retryAfter) ? min((int) $retryAfter, 3600) : null,
        );
    }

    /** No answer at all: timeout, DNS failure, connection reset. */
    public static function fromConnection(ConnectionException $e): self
    {
        return new self('Could not reach Pixelforge: '.$e->getMessage());
    }

    /**
     * True when a generation may exist at Pixelforge (and will be billed) even though this call
     * failed:
     * - no answer at all (timeout, network error, non-JSON body), or a 5xx that is not the
     *   documented `overloaded` (docs: "Nothing was created"): we cannot know what happened;
     * - 409 idempotency_key_reused: a generation ALREADY EXISTS under this key (docs, Idempotency).
     * Every other failure is a definite rejection: nothing was created.
     *
     * The caller must NEVER mark its own row failed because of an unknown outcome, not even after
     * it runs out of attempts. For no answer / 5xx: retry the same request later with the same
     * Idempotency-Key (a replay is not billed and does not count against the concurrency or daily
     * limits, but it DOES count toward the 60 requests per minute) or ask GET /generations/{id}.
     * For 409: do not resend (decision() is Fail); wait for the generation's webhook or poll it.
     */
    public function isUnknownOutcome(): bool
    {
        return $this->status === null
            || $this->status === 409
            || ($this->status >= 500 && $this->errorCode !== 'overloaded');
    }

    /**
     * The one place that decides what a failure means. Spec: docs "Errors" and "Rate limits".
     * Rows marked NEW are not in the docs; they are our own choices.
     */
    public function decision(): Decision
    {
        // NEW: no response (timeout, network error): the request may or may not have arrived;
        // retrying is safe because we always send an Idempotency-Key.
        if ($this->status === null) {
            return Decision::Retry;
        }

        return match ($this->errorCode) {
            // 429, Retry-After: 5. A slot frees up when one of our own generations finishes.
            'concurrency_limit_exceeded' => Decision::Wait,
            // 429, Retry-After in seconds.
            'rate_limit_exceeded' => Decision::Retry,
            // 503, Retry-After: 2. Nothing was created.
            'overloaded' => Decision::Retry,
            // 429. The quota resets at the next UTC day, retrying now cannot help.
            'daily_limit_exceeded' => Decision::Fail,
            // Everything else: invalid_api_key 401, content_policy_violation 400, validation_failed
            // 422, not_found 404 are the caller's fault, and idempotency_key_reused 409 means a
            // generation already exists under this key (never resend; see isUnknownOutcome()).
            // Same request, same answer. NEW: an unknown 5xx is Pixelforge's fault and may pass; an unknown 429
            // is a limit that may pass (nothing was created); any other unknown 4xx is ours.
            default => $this->status >= 500 || $this->status === 429 ? Decision::Retry : Decision::Fail,
        };
    }
}
