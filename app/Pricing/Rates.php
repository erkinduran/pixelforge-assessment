<?php

namespace App\Pricing;

use App\Support\InvalidMoneyException;
use App\Support\Money;

/** Price of one token of each kind, in picodollars. */
final class Rates
{
    public function __construct(
        public readonly int $text,
        public readonly int $image,
        public readonly int $output,
        public readonly int $reasoning,
    ) {}

    /**
     * Builds from the `rates` object Pixelforge sends, e.g. {"input_text_tokens": "5.00", ...}.
     * The values are USD per 1M tokens, as strings.
     *
     * @param  array<string, mixed>  $rates
     */
    public static function fromPixelforge(array $rates): self
    {
        return new self(
            text: self::read($rates, 'input_text_tokens'),
            image: self::read($rates, 'input_image_tokens'),
            output: self::read($rates, 'output_tokens'),
            reasoning: self::read($rates, 'reasoning_tokens'),
        );
    }

    /** @param  array<string, mixed>  $rates */
    private static function read(array $rates, string $key): int
    {
        if (! isset($rates[$key]) || ! is_string($rates[$key])) {
            throw new InvalidMoneyException("Rate \"{$key}\" is missing or is not a string");
        }

        return Money::parseRatePerMillion($rates[$key]);
    }
}
