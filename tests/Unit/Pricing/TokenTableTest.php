<?php

namespace Tests\Unit\Pricing;

use App\Pricing\TokenTable;
use App\Support\InvalidMoneyException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TokenTableTest extends TestCase
{
    use PricingFixture;

    public function test_docs_scalars_are_read(): void
    {
        $table = $this->docsTokenTable();

        $this->assertSame(4, $table->charactersPerTextToken);
        $this->assertSame(765, $table->perReferenceImage);
    }

    public function test_variance_0_15_becomes_15_percent(): void
    {
        // The fixture is decoded with json_decode, so 0.15 arrives as a PHP float.
        $this->assertSame(15, $this->docsTokenTable()->variancePercent);
    }

    public function test_variance_given_as_a_string_works_too(): void
    {
        $tokens = $this->pricingResponse()['tokens'];
        $tokens['text_token_variance'] = '0.20';

        $this->assertSame(20, TokenTable::fromPricing($tokens)->variancePercent);
    }

    public function test_variance_that_is_not_a_whole_percent_throws(): void
    {
        $tokens = $this->pricingResponse()['tokens'];
        $tokens['text_token_variance'] = '0.155';

        $this->expectException(InvalidMoneyException::class);

        TokenTable::fromPricing($tokens);
    }

    public function test_variance_is_read_correctly_even_when_the_precision_ini_is_high(): void
    {
        // (string) 0.15 would give "0.14999999999999999" with precision 17.
        $old = ini_set('precision', '17');

        try {
            $this->assertSame(15, $this->docsTokenTable()->variancePercent);
        } finally {
            ini_set('precision', (string) $old);
        }
    }

    public function test_variance_of_exactly_100_percent_is_allowed(): void
    {
        $tokens = $this->pricingResponse()['tokens'];
        $tokens['text_token_variance'] = '1.00';

        $this->assertSame(100, TokenTable::fromPricing($tokens)->variancePercent);
    }

    public function test_variance_above_100_percent_throws(): void
    {
        $tokens = $this->pricingResponse()['tokens'];
        $tokens['text_token_variance'] = '1.01';

        $this->expectException(InvalidArgumentException::class);

        TokenTable::fromPricing($tokens);
    }

    public function test_negative_variance_throws(): void
    {
        $tokens = $this->pricingResponse()['tokens'];
        $tokens['text_token_variance'] = -0.15;

        $this->expectException(InvalidMoneyException::class);

        TokenTable::fromPricing($tokens);
    }

    public function test_negative_per_reference_image_throws(): void
    {
        $tokens = $this->pricingResponse()['tokens'];
        $tokens['per_reference_image'] = -765;

        $this->expectException(InvalidArgumentException::class);

        TokenTable::fromPricing($tokens);
    }

    public function test_negative_output_tokens_throw(): void
    {
        $tokens = $this->pricingResponse()['tokens'];
        $tokens['output']['low']['1024x1024'] = -280;

        $this->expectException(InvalidArgumentException::class);

        TokenTable::fromPricing($tokens);
    }

    public function test_negative_reasoning_min_throws(): void
    {
        $tokens = $this->pricingResponse()['tokens'];
        $tokens['reasoning']['low'] = ['min' => -1, 'max' => 500];

        $this->expectException(InvalidArgumentException::class);

        TokenTable::fromPricing($tokens);
    }

    public function test_negative_reasoning_max_throws(): void
    {
        $tokens = $this->pricingResponse()['tokens'];
        $tokens['reasoning']['low'] = ['min' => -5, 'max' => -1];

        $this->expectException(InvalidArgumentException::class);

        TokenTable::fromPricing($tokens);
    }

    public function test_output_tokens_by_quality_and_size(): void
    {
        $table = $this->docsTokenTable();

        $this->assertSame(280, $table->outputTokens('low', '1024x1024'));
        $this->assertSame(1650, $table->outputTokens('medium', '1024x1536'));
        $this->assertSame(6450, $table->outputTokens('high', '1536x1024'));
    }

    public function test_reasoning_range_by_quality(): void
    {
        $table = $this->docsTokenTable();

        $this->assertSame(['min' => 0, 'max' => 500], $table->reasoningRange('low'));
        $this->assertSame(['min' => 1000, 'max' => 8000], $table->reasoningRange('high'));
    }

    public function test_unknown_quality_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->docsTokenTable()->outputTokens('ultra', '1024x1024');
    }

    public function test_unknown_size_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->docsTokenTable()->outputTokens('low', '1x1');
    }

    public function test_missing_reasoning_section_throws(): void
    {
        $tokens = $this->pricingResponse()['tokens'];
        unset($tokens['reasoning']);

        $this->expectException(InvalidArgumentException::class);

        TokenTable::fromPricing($tokens);
    }

    public function test_reasoning_with_min_above_max_throws(): void
    {
        $tokens = $this->pricingResponse()['tokens'];
        $tokens['reasoning']['low'] = ['min' => 600, 'max' => 500];

        $this->expectException(InvalidArgumentException::class);

        TokenTable::fromPricing($tokens);
    }

    public function test_zero_characters_per_token_throws(): void
    {
        $tokens = $this->pricingResponse()['tokens'];
        $tokens['characters_per_text_token'] = 0;

        $this->expectException(InvalidArgumentException::class);

        TokenTable::fromPricing($tokens);
    }
}
