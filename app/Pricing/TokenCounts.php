<?php

namespace App\Pricing;

use InvalidArgumentException;

/** How many tokens of each kind a generation used (or may use). */
final class TokenCounts
{
    public function __construct(
        public readonly int $text,
        public readonly int $image,
        public readonly int $output,
        public readonly int $reasoning,
    ) {
        if ($text < 0 || $image < 0 || $output < 0 || $reasoning < 0) {
            throw new InvalidArgumentException('Token counts cannot be negative');
        }
    }

    /**
     * Builds from the `usage` object Pixelforge sends on a succeeded generation.
     *
     * @param  array<string, mixed>  $usage
     */
    public static function fromPixelforge(array $usage): self
    {
        foreach (['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'] as $key) {
            if (! isset($usage[$key]) || ! is_int($usage[$key])) {
                throw new InvalidArgumentException("Usage \"{$key}\" is missing or is not an integer");
            }
        }

        return new self(
            text: $usage['input_text_tokens'],
            image: $usage['input_image_tokens'],
            output: $usage['output_tokens'],
            reasoning: $usage['reasoning_tokens'],
        );
    }
}
