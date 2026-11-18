<?php

namespace Tests\Unit\Support;

use App\Support\InvalidMoneyException;
use App\Support\Money;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    // --- parseRatePerMillion: "USD per 1M tokens" -> picodollars per token (x 10^6) ---

    public function test_rate_5_dollars_per_million_is_5_million_pico_per_token(): void
    {
        $this->assertSame(5_000_000, Money::parseRatePerMillion('5.00'));
    }

    public function test_rate_with_trailing_zeros_parses_the_same(): void
    {
        $this->assertSame(Money::parseRatePerMillion('5.00'), Money::parseRatePerMillion('5.000000'));
    }

    public function test_rate_12_50_parses(): void
    {
        $this->assertSame(12_500_000, Money::parseRatePerMillion('12.50'));
    }

    public function test_rate_with_4_decimals_is_the_finest_allowed(): void
    {
        $this->assertSame(100, Money::parseRatePerMillion('0.0001'));
    }

    public function test_rate_with_5_decimals_throws(): void
    {
        $this->expectException(InvalidMoneyException::class);
        $this->expectExceptionMessage('more than 4 decimal places');

        Money::parseRatePerMillion('0.00001');
    }

    /** @return array<string, array{string}> */
    public static function garbageProvider(): array
    {
        return [
            'negative' => ['-5.00'],
            'letters' => ['abc'],
            'empty' => [''],
            'trailing dot' => ['5.'],
            'leading dot' => ['.5'],
            'exponent' => ['1e3'],
            'comma' => ['5,00'],
            'space' => [' 5.00'],
            'trailing newline' => ["5.00\n"],
            'two dots' => ['5.0.0'],
        ];
    }

    #[DataProvider('garbageProvider')]
    public function test_rate_garbage_throws(string $garbage): void
    {
        $this->expectException(InvalidMoneyException::class);

        Money::parseRatePerMillion($garbage);
    }

    #[DataProvider('garbageProvider')]
    public function test_usd_garbage_throws(string $garbage): void
    {
        $this->expectException(InvalidMoneyException::class);

        Money::parseUsd($garbage);
    }

    public function test_a_value_too_big_for_an_int_throws_instead_of_overflowing(): void
    {
        $this->expectException(InvalidMoneyException::class);
        $this->expectExceptionMessage('too large');

        Money::parseUsd('99999999999.000000000000');
    }

    // --- parseUsd: USD -> picodollars (x 10^12) ---

    public function test_usd_docs_max_example_parses_to_pico(): void
    {
        $this->assertSame(373_875_000_000, Money::parseUsd('0.373875000'));
    }

    public function test_usd_usage_example_parses_to_pico(): void
    {
        $this->assertSame(169_080_000_000, Money::parseUsd('0.169080000'));
    }

    public function test_usd_zero_with_nine_decimals_is_zero(): void
    {
        $this->assertSame(0, Money::parseUsd('0.000000000'));
    }

    public function test_usd_12_decimals_is_the_finest_allowed(): void
    {
        $this->assertSame(1, Money::parseUsd('0.000000000001'));
    }

    public function test_usd_13_decimals_throws(): void
    {
        $this->expectException(InvalidMoneyException::class);

        Money::parseUsd('0.0000000000001');
    }

    public function test_usd_without_decimals_parses(): void
    {
        $this->assertSame(5_000_000_000_000, Money::parseUsd('5'));
    }

    // --- withMarkup: pico x 5 / 4, exact ---

    public function test_markup_adds_25_percent(): void
    {
        $this->assertSame(500, Money::withMarkup(400));
    }

    // derived: 305_510_000_000 x 5 / 4 (see CostCalculatorTest)
    public function test_markup_on_a_real_cost_stays_a_whole_number(): void
    {
        $this->assertSame(381_887_500_000, Money::withMarkup(305_510_000_000));
    }

    public function test_markup_of_zero_is_zero(): void
    {
        $this->assertSame(0, Money::withMarkup(0));
    }

    public function test_markup_throws_when_times_five_is_not_divisible_by_4(): void
    {
        // 1 x 5 = 5, and 5 % 4 = 1: the result would need rounding.
        $this->expectException(InvalidMoneyException::class);
        $this->expectExceptionMessage('not divisible by 4');

        Money::withMarkup(1);
    }

    public function test_markup_throws_on_a_negative_amount(): void
    {
        $this->expectException(InvalidMoneyException::class);

        Money::withMarkup(-4);
    }

    public function test_markup_throws_instead_of_overflowing(): void
    {
        $this->expectException(InvalidMoneyException::class);

        Money::withMarkup(PHP_INT_MAX - 3);
    }

    // --- multiply ---

    public function test_multiply_tokens_by_pico_per_token(): void
    {
        $this->assertSame(575_000_000, Money::multiply(115, 5_000_000));
    }

    public function test_multiply_throws_instead_of_overflowing_to_a_float(): void
    {
        $this->expectException(InvalidMoneyException::class);

        Money::multiply(PHP_INT_MAX, 2);
    }

    public function test_multiply_throws_on_a_negative_token_count(): void
    {
        $this->expectException(InvalidMoneyException::class);

        Money::multiply(-1, 5_000_000);
    }

    public function test_multiply_by_a_zero_rate_is_zero(): void
    {
        $this->assertSame(0, Money::multiply(1000, 0));
    }

    // --- add ---

    public function test_add_sums_two_amounts(): void
    {
        $this->assertSame(1_500, Money::add(1_000, 500));
    }

    public function test_add_throws_instead_of_overflowing(): void
    {
        $this->expectException(InvalidMoneyException::class);

        Money::add(PHP_INT_MAX, 1);
    }

    public function test_add_throws_on_a_negative_amount(): void
    {
        $this->expectException(InvalidMoneyException::class);

        Money::add(-1, 5);
    }

    // --- parseDecimal guard ---

    public function test_parse_decimal_throws_a_logic_error_when_max_decimals_exceeds_scale(): void
    {
        $this->expectException(LogicException::class);

        Money::parseDecimal('1', 5, 4);
    }

    // --- formatUsd: display only, exact ---

    /** @return array<string, array{int, string}> */
    public static function formatProvider(): array
    {
        return [
            'docs max example' => [373_875_000_000, '$0.373875'],
            'user price with 7 decimals' => [381_887_500_000, '$0.3818875'],
            'whole dollars keep 2 decimals' => [5_000_000_000_000, '$5.00'],
            'one decimal is padded to 2' => [300_000_000_000, '$0.30'],
            'zero' => [0, '$0.00'],
            'smallest unit is shown exactly' => [1, '$0.000000000001'],
            'dollars and a fraction' => [12_345_678_900_000, '$12.3456789'],
            'negative (e.g. a loss)' => [-250_000_000_000, '-$0.25'],
        ];
    }

    #[DataProvider('formatProvider')]
    public function test_format_usd_is_exact(int $pico, string $expected): void
    {
        $this->assertSame($expected, Money::formatUsd($pico));
    }
}
