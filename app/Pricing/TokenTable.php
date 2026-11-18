<?php

namespace App\Pricing;

use App\Support\Money;
use InvalidArgumentException;

/** The `tokens` part of GET /v1/pricing: how many tokens a request is expected to use. */
final class TokenTable
{
    /**
     * @param  int  $variancePercent  the text-token estimate can be off by this many percent either way
     * @param  array<string, array<string, int>>  $output  exact output tokens, by quality then size
     * @param  array<string, array{min: int, max: int}>  $reasoning  reasoning tokens range, by quality
     */
    public function __construct(
        public readonly int $charactersPerTextToken,
        public readonly int $variancePercent,
        public readonly int $perReferenceImage,
        private readonly array $output,
        private readonly array $reasoning,
    ) {
        if ($charactersPerTextToken < 1) {
            throw new InvalidArgumentException('characters_per_text_token must be at least 1');
        }
    }

    /**
     * Builds from the decoded `tokens` object of /v1/pricing.
     *
     * @param  array<string, mixed>  $tokens
     */
    public static function fromPricing(array $tokens): self
    {
        // json_decode turns 0.15 into a float. json_encode writes it back as the shortest
        // text that round-trips ("0.15", whatever the `precision` ini says); we read that text as 15%.
        $variance = $tokens['text_token_variance'] ?? null;
        if (is_int($variance) || is_float($variance)) {
            $variance = json_encode($variance, JSON_THROW_ON_ERROR);
        }
        if (! is_string($variance)) {
            throw new InvalidArgumentException('text_token_variance is missing or not a number');
        }

        $variancePercent = Money::parseDecimal($variance, 2, 2);
        if ($variancePercent > 100) {
            throw new InvalidArgumentException('text_token_variance must be between 0 and 1');
        }

        return new self(
            charactersPerTextToken: self::readInt($tokens, 'characters_per_text_token'),
            variancePercent: $variancePercent,
            perReferenceImage: self::readInt($tokens, 'per_reference_image'),
            output: self::readOutput($tokens),
            reasoning: self::readReasoning($tokens),
        );
    }

    public function outputTokens(string $quality, string $size): int
    {
        return $this->output[$quality][$size]
            ?? throw new InvalidArgumentException("No output tokens for quality \"{$quality}\" and size \"{$size}\"");
    }

    /** @return array{min: int, max: int} */
    public function reasoningRange(string $quality): array
    {
        return $this->reasoning[$quality]
            ?? throw new InvalidArgumentException("No reasoning range for quality \"{$quality}\"");
    }

    /** @param  array<string, mixed>  $data */
    private static function readInt(array $data, string $key): int
    {
        if (! isset($data[$key]) || ! is_int($data[$key]) || $data[$key] < 0) {
            throw new InvalidArgumentException("\"{$key}\" is missing or is not a non-negative integer");
        }

        return $data[$key];
    }

    /**
     * @param  array<string, mixed>  $tokens
     * @return array<string, array<string, int>>
     */
    private static function readOutput(array $tokens): array
    {
        $output = [];

        foreach (self::asArray($tokens['output'] ?? null, 'output') as $quality => $sizes) {
            foreach (self::asArray($sizes, "output.{$quality}") as $size => $count) {
                if (! is_int($count) || $count < 0) {
                    throw new InvalidArgumentException("output.{$quality}.{$size} is not a non-negative integer");
                }
                $output[(string) $quality][(string) $size] = $count;
            }
        }

        return $output;
    }

    /**
     * @param  array<string, mixed>  $tokens
     * @return array<string, array{min: int, max: int}>
     */
    private static function readReasoning(array $tokens): array
    {
        $reasoning = [];

        foreach (self::asArray($tokens['reasoning'] ?? null, 'reasoning') as $quality => $range) {
            $range = self::asArray($range, "reasoning.{$quality}");
            $min = self::readInt($range, 'min');
            $max = self::readInt($range, 'max');

            if ($min > $max) {
                throw new InvalidArgumentException("reasoning.{$quality}: min is greater than max");
            }
            $reasoning[(string) $quality] = ['min' => $min, 'max' => $max];
        }

        return $reasoning;
    }

    /** @return array<string, mixed> */
    private static function asArray(mixed $value, string $name): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException("\"{$name}\" is missing or is not an object");
        }

        return $value;
    }
}
