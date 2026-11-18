<?php

namespace App\Pricing;

use InvalidArgumentException;

final class Estimator
{
    public function __construct(private readonly CostCalculator $calculator) {}

    /**
     * Cheapest and most expensive outcome for a request, before it is sent.
     * Exact parts: output tokens, reference image tokens.
     * Uncertain parts: text tokens (within the variance) and reasoning tokens (within the quality's range).
     */
    public function estimate(
        string $prompt,
        string $size,
        string $quality,
        int $referenceImages,
        Rates $rates,
        TokenTable $table,
    ): Estimate {
        if ($referenceImages < 0) {
            throw new InvalidArgumentException('Reference image count cannot be negative');
        }

        // Characters, not bytes: "ğ" is 1 character but 2 bytes.
        $characters = mb_strlen($prompt, 'UTF-8');
        $perToken = $table->charactersPerTextToken;
        $estimatedText = intdiv($characters + $perToken - 1, $perToken); // divide, rounding up

        // Bounds are rounded outward (low down, high up) so the real count is never outside the range.
        $variance = $table->variancePercent;
        $lowText = intdiv($estimatedText * (100 - $variance), 100);
        $highText = intdiv($estimatedText * (100 + $variance) + 99, 100);

        $image = $referenceImages * $table->perReferenceImage;
        $output = $table->outputTokens($quality, $size);
        $reasoning = $table->reasoningRange($quality);

        $min = new TokenCounts($lowText, $image, $output, $reasoning['min']);
        $max = new TokenCounts($highText, $image, $output, $reasoning['max']);

        return new Estimate(
            minTokens: $min,
            maxTokens: $max,
            minProviderCost: $this->calculator->providerCost($min, $rates),
            maxProviderCost: $this->calculator->providerCost($max, $rates),
            minUserPrice: $this->calculator->userPrice($min, $rates),
            maxUserPrice: $this->calculator->userPrice($max, $rates),
        );
    }
}
