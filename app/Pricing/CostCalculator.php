<?php

namespace App\Pricing;

use App\Support\Money;

final class CostCalculator
{
    /** What Pixelforge bills us, in picodollars. Always an exact whole number. */
    public function providerCost(TokenCounts $tokens, Rates $rates): int
    {
        $text = Money::multiply($tokens->text, $rates->text);
        $image = Money::multiply($tokens->image, $rates->image);
        $output = Money::multiply($tokens->output, $rates->output);
        $reasoning = Money::multiply($tokens->reasoning, $rates->reasoning);

        return Money::add(Money::add($text, $image), Money::add($output, $reasoning));
    }

    /** What the user pays: provider cost plus the 25% markup. */
    public function userPrice(TokenCounts $tokens, Rates $rates): int
    {
        return Money::withMarkup($this->providerCost($tokens, $rates));
    }
}
