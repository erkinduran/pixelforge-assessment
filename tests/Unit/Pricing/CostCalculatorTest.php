<?php

namespace Tests\Unit\Pricing;

use App\Pricing\CostCalculator;
use App\Pricing\Rates;
use App\Pricing\TokenCounts;
use App\Support\InvalidMoneyException;
use PHPUnit\Framework\TestCase;

class CostCalculatorTest extends TestCase
{
    use PricingFixture;

    public function test_docs_max_example_costs_exactly_0_373875_dollars(): void
    {
        // Docs "most a generation can cost": 115 text, 1530 image, 6450 output, 8000 reasoning.
        $tokens = new TokenCounts(text: 115, image: 1530, output: 6450, reasoning: 8000);

        $cost = (new CostCalculator)->providerCost($tokens, $this->docsRates());

        $this->assertSame(373_875_000_000, $cost);
    }

    public function test_docs_usage_example_costs_exactly_0_16908_dollars(): void
    {
        // Docs GET /v1/usage, 2026-11-01 (3 generations): cost_usd "0.169080000".
        $tokens = new TokenCounts(text: 41, image: 0, output: 3300, reasoning: 2950);

        $cost = (new CostCalculator)->providerCost($tokens, $this->docsRates());

        $this->assertSame(169_080_000_000, $cost);
    }

    public function test_succeeded_generation_example_provider_cost_and_user_price(): void
    {
        // Docs "succeeded" generation: usage 12 / 765 / 6450 / 3184 at 5.00 / 10.00 / 40.00 / 12.50.
        $tokens = new TokenCounts(text: 12, image: 765, output: 6450, reasoning: 3184);
        $calculator = new CostCalculator;

        // The docs do not print a price for this one: both numbers below are derived, not quoted.
        // derived: 12x5 + 765x10 + 6450x40 + 3184x12.5 = 305_510 micro-dollars = $0.30551
        $this->assertSame(305_510_000_000, $calculator->providerCost($tokens, $this->docsRates()));
        // derived: 305_510_000_000 x 5 / 4 = $0.3818875
        $this->assertSame(381_887_500_000, $calculator->userPrice($tokens, $this->docsRates()));
    }

    public function test_no_tokens_cost_nothing(): void
    {
        $tokens = new TokenCounts(text: 0, image: 0, output: 0, reasoning: 0);

        $this->assertSame(0, (new CostCalculator)->providerCost($tokens, $this->docsRates()));
        $this->assertSame(0, (new CostCalculator)->userPrice($tokens, $this->docsRates()));
    }

    public function test_a_sum_that_overflows_throws(): void
    {
        // Each product fits in an int on its own (5e18 each), but their sum does not.
        $rates = new Rates(text: 1_000_000_000, image: 1_000_000_000, output: 1, reasoning: 1);
        $tokens = new TokenCounts(text: 5_000_000_000, image: 5_000_000_000, output: 0, reasoning: 0);

        $this->expectException(InvalidMoneyException::class);

        (new CostCalculator)->providerCost($tokens, $rates);
    }

    public function test_user_price_is_provider_cost_plus_25_percent(): void
    {
        $tokens = new TokenCounts(text: 115, image: 1530, output: 6450, reasoning: 8000);

        $price = (new CostCalculator)->userPrice($tokens, $this->docsRates());

        // derived: 373_875_000_000 x 5 / 4
        $this->assertSame(467_343_750_000, $price);
    }
}
