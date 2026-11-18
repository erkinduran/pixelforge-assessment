<?php

namespace App\Pricing;

/**
 * Cost range shown before submitting. All money is integer picodollars.
 * "Provider cost" is what Pixelforge charges us; "user price" adds the 25% markup.
 */
final class Estimate
{
    public function __construct(
        public readonly TokenCounts $minTokens,
        public readonly TokenCounts $maxTokens,
        public readonly int $minProviderCost,
        public readonly int $maxProviderCost,
        public readonly int $minUserPrice,
        public readonly int $maxUserPrice,
    ) {}
}
