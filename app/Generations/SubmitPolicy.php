<?php

namespace App\Generations;

use App\Pixelforge\Decision;
use App\Pixelforge\PixelforgeException;
use Carbon\CarbonInterface;

/**
 * What to do after a failed POST /generations. Pure: no database,
 * no queue, no clock of its own, so it can be tested as a table.
 */
final class SubmitPolicy
{
    /** Failed attempts worth retrying (rate limit, overloaded, timeout, 5xx) before we stop. */
    public const MAX_ATTEMPTS = 5;

    /** How long one dispatch of the job keeps waiting for a free concurrency slot. */
    public const WAIT_BUDGET_SECONDS = 600;

    /** Seconds to wait when the concurrency 429 has no Retry-After (docs: 5). */
    private const DEFAULT_WAIT_SECONDS = 5;

    /**
     * @param  int  $attempts  Retry-type failures BEFORE this one (the row's submit_attempts).
     * @param  CarbonInterface|null  $unknownOutcomeAt  When an attempt first had an unknown outcome. If this
     *                                                  exception is itself unknown, the caller has already set it.
     * @param  CarbonInterface  $waitingSince  When this dispatch of the job started; the wait budget counts from here.
     */
    public function next(PixelforgeException $e, int $attempts, ?CarbonInterface $unknownOutcomeAt, CarbonInterface $waitingSince, CarbonInterface $now): SubmitAction
    {
        // The row may exist at Pixelforge: we must never mark it failed ourselves.
        $mayExist = $unknownOutcomeAt !== null;

        switch ($e->decision()) {
            case Decision::Wait:
                // A slot frees up when one of our generations finishes. Waiting is not a failure,
                // so it does not use attempts. After the budget we stop; the row stays queued.
                if ($waitingSince->diffInSeconds($now, absolute: true) >= self::WAIT_BUDGET_SECONDS) {
                    return SubmitAction::giveUp();
                }

                return SubmitAction::release(max(1, $e->retryAfter ?? self::DEFAULT_WAIT_SECONDS));

            case Decision::Retry:
                if ($attempts + 1 < self::MAX_ATTEMPTS) {
                    // Honour Retry-After, and back off further each time: 2, 4, 8, 16 s.
                    return SubmitAction::release(max($e->retryAfter ?? 0, 2 ** ($attempts + 1)));
                }

                return $mayExist ? SubmitAction::giveUp() : $this->fail($e);

            default: // Decision::Fail
                // 409: a generation already exists under our key; resending cannot help.
                // Any other definite rejection is written as failed, unless an earlier attempt was unknown.
                return $e->isUnknownOutcome() || $mayExist ? SubmitAction::giveUp() : $this->fail($e);
        }
    }

    private function fail(PixelforgeException $e): SubmitAction
    {
        $code = $e->errorCode ?? 'submit_failed';

        $message = match ($code) {
            'daily_limit_exceeded' => 'The daily generation limit is used up. Try again after 00:00 UTC.',
            'content_policy_violation' => 'The prompt was rejected by the content policy. Change the prompt and try again.',
            'invalid_api_key' => 'The image service did not accept our credentials. Please contact the site owner.',
            'rate_limit_exceeded' => 'The image service is receiving too many requests. We gave up after '.self::MAX_ATTEMPTS.' attempts. Please try again later.',
            'overloaded' => 'The image service is overloaded. We gave up after '.self::MAX_ATTEMPTS.' attempts. Please try again later.',
            // validation_failed and anything unknown: no provider text for the user (it is logged by the job).
            default => 'The request could not be processed. Please try again.',
        };

        return SubmitAction::fail($code, $message);
    }
}
