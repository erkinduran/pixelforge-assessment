<?php

namespace App\Support;

use LogicException;

/**
 * Money is an int number of picodollars: 1 USD = 10^12.
 * Nothing in here uses floats; decimal strings are read digit by digit.
 */
final class Money
{
    public const PICO_PER_USD = 1_000_000_000_000;

    private const USD_DECIMALS = 12;

    // A rate is "USD per 1M tokens". Multiplying by 10^6 turns it into picodollars per token.
    private const RATE_SCALE = 6;

    // With at most 4 decimals the price of every token is a multiple of 100 picodollars,
    // so the 25% markup (x5/4) always lands on a whole picodollar.
    private const RATE_MAX_DECIMALS = 4;

    // Longest digit string that is always below PHP_INT_MAX (9223372036854775807).
    private const MAX_DIGITS = 18;

    /** "5.00" (USD per 1M tokens) -> 5_000_000 picodollars per token. */
    public static function parseRatePerMillion(string $rate): int
    {
        return self::parseDecimal($rate, self::RATE_MAX_DECIMALS, self::RATE_SCALE);
    }

    /** "0.373875000" (USD) -> 373_875_000_000 picodollars. */
    public static function parseUsd(string $usd): int
    {
        return self::parseDecimal($usd, self::USD_DECIMALS, self::USD_DECIMALS);
    }

    /**
     * Reads a non-negative decimal string and returns value x 10^$scale as an int.
     * Trailing zeros do not count as decimals ("5.000000" is fine for $maxDecimals = 4).
     * More real decimals than $maxDecimals is an error: we never round.
     */
    public static function parseDecimal(string $value, int $maxDecimals, int $scale): int
    {
        // Programming error, not bad input: the digits are padded to $scale, so more decimals would not fit.
        if ($maxDecimals > $scale) {
            throw new LogicException("maxDecimals ({$maxDecimals}) cannot be larger than scale ({$scale})");
        }

        if (preg_match('/^(\d+)(?:\.(\d+))?$/D', $value, $match) !== 1) {
            throw new InvalidMoneyException("Not a non-negative decimal number: \"{$value}\"");
        }

        $whole = $match[1];
        $decimals = rtrim($match[2] ?? '', '0');

        if (strlen($decimals) > $maxDecimals) {
            throw new InvalidMoneyException("\"{$value}\" has more than {$maxDecimals} decimal places");
        }

        // Whole part followed by the decimals padded to $scale digits, e.g. "5" . "000000".
        $digits = ltrim($whole.str_pad($decimals, $scale, '0'), '0');

        if (strlen($digits) > self::MAX_DIGITS) {
            throw new InvalidMoneyException("\"{$value}\" is too large");
        }

        return (int) $digits;
    }

    /** tokens x pico per token. Throws before the result could overflow PHP's int. */
    public static function multiply(int $tokens, int $picoPerToken): int
    {
        if ($tokens < 0 || $picoPerToken < 0) {
            throw new InvalidMoneyException("Cannot multiply {$tokens} x {$picoPerToken}: negative");
        }

        if ($picoPerToken > 0 && $tokens > intdiv(PHP_INT_MAX, $picoPerToken)) {
            throw new InvalidMoneyException("{$tokens} x {$picoPerToken} does not fit in an integer");
        }

        return $tokens * $picoPerToken;
    }

    /** a + b. Throws before the result could overflow PHP's int. */
    public static function add(int $a, int $b): int
    {
        if ($a < 0 || $b < 0 || $a > PHP_INT_MAX - $b) {
            throw new InvalidMoneyException("Cannot add {$a} + {$b}: negative or too large");
        }

        return $a + $b;
    }

    /** Adds the 25% markup: pico x 5 / 4. Throws if the division would leave a remainder. */
    public static function withMarkup(int $pico): int
    {
        if ($pico < 0 || $pico > intdiv(PHP_INT_MAX, 5)) {
            throw new InvalidMoneyException("Cannot apply markup to {$pico} picodollars");
        }

        $timesFive = $pico * 5;

        if ($timesFive % 4 !== 0) {
            throw new InvalidMoneyException("{$pico} x 5 is not divisible by 4; refusing to round");
        }

        return intdiv($timesFive, 4);
    }

    /**
     * Display only. Exact: 373_875_000_000 -> "$0.373875".
     * Trailing zeros are dropped so the exact value reads cleanly, but at least
     * 2 decimals are kept ("$5.00"). Nothing is rounded, so what we show can always
     * be traced back to the stored integer.
     */
    public static function formatUsd(int $pico): string
    {
        $sign = $pico < 0 ? '-' : '';
        $digits = str_pad(ltrim((string) $pico, '-'), self::USD_DECIMALS + 1, '0', STR_PAD_LEFT);

        $whole = substr($digits, 0, -self::USD_DECIMALS);
        $decimals = rtrim(substr($digits, -self::USD_DECIMALS), '0');
        $decimals = str_pad($decimals, 2, '0');

        return "{$sign}\${$whole}.{$decimals}";
    }
}
