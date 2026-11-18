<?php

namespace Tests\Unit\Pricing;

use App\Pricing\Rates;
use App\Support\InvalidMoneyException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RatesTest extends TestCase
{
    use PricingFixture;

    public function test_docs_rates_become_pico_per_token(): void
    {
        $rates = $this->docsRates();

        $this->assertSame(5_000_000, $rates->text);
        $this->assertSame(10_000_000, $rates->image);
        $this->assertSame(40_000_000, $rates->output);
        $this->assertSame(12_500_000, $rates->reasoning);
    }

    public function test_a_missing_rate_throws(): void
    {
        $this->expectException(InvalidMoneyException::class);
        $this->expectExceptionMessage('reasoning_tokens');

        Rates::fromPixelforge([
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
        ]);
    }

    /** @return array<string, array{mixed}> */
    public static function notAStringProvider(): array
    {
        return [
            'float' => [5.0],
            'int' => [5],
            'null' => [null],
        ];
    }

    #[DataProvider('notAStringProvider')]
    public function test_a_rate_that_is_not_a_string_throws(mixed $notAString): void
    {
        $this->expectException(InvalidMoneyException::class);

        Rates::fromPixelforge([
            'input_text_tokens' => $notAString,
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ]);
    }

    public function test_a_rate_with_more_than_4_decimals_throws(): void
    {
        $this->expectException(InvalidMoneyException::class);

        Rates::fromPixelforge([
            'input_text_tokens' => '5.00001',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ]);
    }
}
