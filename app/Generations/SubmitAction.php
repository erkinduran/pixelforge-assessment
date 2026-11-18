<?php

namespace App\Generations;

/** The answer of SubmitPolicy::next(): a step plus the data that step needs. */
final readonly class SubmitAction
{
    private function __construct(
        public SubmitStep $step,
        public int $seconds = 0,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
    ) {}

    public static function release(int $seconds): self
    {
        return new self(SubmitStep::Release, seconds: $seconds);
    }

    public static function fail(string $code, string $message): self
    {
        return new self(SubmitStep::Fail, failureCode: $code, failureMessage: $message);
    }

    public static function giveUp(): self
    {
        return new self(SubmitStep::GiveUp);
    }
}
