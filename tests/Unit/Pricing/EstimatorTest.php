<?php

namespace Tests\Unit\Pricing;

use App\Pricing\CostCalculator;
use App\Pricing\Estimator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class EstimatorTest extends TestCase
{
    use PricingFixture;

    private function estimator(): Estimator
    {
        return new Estimator(new CostCalculator);
    }

    public function test_docs_worked_example_maximum_is_exactly_0_373875_dollars(): void
    {
        // Docs: high, 1536x1024, 400-character prompt, 2 reference images.
        $estimate = $this->estimator()->estimate(
            prompt: str_repeat('a', 400),
            size: '1536x1024',
            quality: 'high',
            referenceImages: 2,
            rates: $this->docsRates(),
            table: $this->docsTokenTable(),
        );

        $this->assertSame(373_875_000_000, $estimate->maxProviderCost);
        $this->assertSame(115, $estimate->maxTokens->text); // 400 / 4 = 100, +15%
        $this->assertSame(1530, $estimate->maxTokens->image);
        $this->assertSame(6450, $estimate->maxTokens->output);
        $this->assertSame(8000, $estimate->maxTokens->reasoning);
    }

    public function test_docs_worked_example_minimum(): void
    {
        $estimate = $this->estimator()->estimate(
            str_repeat('a', 400), '1536x1024', 'high', 2, $this->docsRates(), $this->docsTokenTable(),
        );

        // derived: 85 text, 1530 image, 6450 output, 1000 reasoning
        $this->assertSame(85, $estimate->minTokens->text);
        $this->assertSame(1000, $estimate->minTokens->reasoning);
        $this->assertSame(286_225_000_000, $estimate->minProviderCost);
    }

    public function test_user_prices_are_provider_costs_plus_25_percent(): void
    {
        $estimate = $this->estimator()->estimate(
            str_repeat('a', 400), '1536x1024', 'high', 2, $this->docsRates(), $this->docsTokenTable(),
        );

        // derived: x 5 / 4
        $this->assertSame(467_343_750_000, $estimate->maxUserPrice);
        $this->assertSame(357_781_250_000, $estimate->minUserPrice);
    }

    public function test_minimum_never_exceeds_maximum(): void
    {
        foreach (['low', 'medium', 'high'] as $quality) {
            foreach (['1024x1024', '1536x1024', '1024x1536'] as $size) {
                foreach ([0, 1, 3] as $images) {
                    $estimate = $this->estimator()->estimate(
                        'A cat in a hat', $size, $quality, $images, $this->docsRates(), $this->docsTokenTable(),
                    );

                    $this->assertLessThanOrEqual($estimate->maxProviderCost, $estimate->minProviderCost);
                    $this->assertLessThanOrEqual($estimate->maxUserPrice, $estimate->minUserPrice);
                }
            }
        }
    }

    public function test_exact_parts_are_the_same_in_min_and_max(): void
    {
        $estimate = $this->estimator()->estimate(
            'A cat in a hat', '1024x1536', 'medium', 3, $this->docsRates(), $this->docsTokenTable(),
        );

        $this->assertSame(3 * 765, $estimate->minTokens->image);
        $this->assertSame($estimate->minTokens->image, $estimate->maxTokens->image);
        $this->assertSame(1650, $estimate->minTokens->output);
        $this->assertSame($estimate->minTokens->output, $estimate->maxTokens->output);
    }

    public function test_reasoning_range_follows_the_quality(): void
    {
        $estimate = $this->estimator()->estimate(
            'A cat', '1024x1024', 'medium', 0, $this->docsRates(), $this->docsTokenTable(),
        );

        $this->assertSame(200, $estimate->minTokens->reasoning);
        $this->assertSame(2000, $estimate->maxTokens->reasoning);
    }

    public function test_text_bounds_round_outward_when_the_percentage_is_not_whole(): void
    {
        // 40 characters -> 10 tokens. 10 x 0.85 = 8.5 -> low 8 (down). 10 x 1.15 = 11.5 -> high 12 (up).
        $estimate = $this->estimator()->estimate(
            str_repeat('a', 40), '1024x1024', 'low', 0, $this->docsRates(), $this->docsTokenTable(),
        );

        $this->assertSame(8, $estimate->minTokens->text);
        $this->assertSame(12, $estimate->maxTokens->text);
    }

    public function test_text_bounds_are_not_widened_when_the_percentage_is_whole(): void
    {
        // 400 characters -> 100 tokens. 85 and 115 are exact, so nothing is added.
        $estimate = $this->estimator()->estimate(
            str_repeat('a', 400), '1024x1024', 'low', 0, $this->docsRates(), $this->docsTokenTable(),
        );

        $this->assertSame(85, $estimate->minTokens->text);
        $this->assertSame(115, $estimate->maxTokens->text);
    }

    public function test_text_tokens_round_up_to_a_whole_token(): void
    {
        // 5 characters / 4 = 1.25 -> 2 tokens. Then 2 x 0.85 = 1.7 -> 1, and 2 x 1.15 = 2.3 -> 3.
        $estimate = $this->estimator()->estimate(
            'abcde', '1024x1024', 'low', 0, $this->docsRates(), $this->docsTokenTable(),
        );

        $this->assertSame(1, $estimate->minTokens->text);
        $this->assertSame(3, $estimate->maxTokens->text);
    }

    public function test_unicode_prompt_counts_characters_not_bytes(): void
    {
        // "ğüşİ" is 4 characters but 8 bytes. Repeated 4 times: 16 characters -> 4 tokens.
        // By characters: low 3, high 5. By bytes (32 -> 8 tokens) it would be 6 and 10.
        $estimate = $this->estimator()->estimate(
            str_repeat('ğüşİ', 4), '1024x1024', 'low', 0, $this->docsRates(), $this->docsTokenTable(),
        );

        $this->assertSame(3, $estimate->minTokens->text);
        $this->assertSame(5, $estimate->maxTokens->text);
    }

    public function test_empty_prompt_has_zero_text_tokens(): void
    {
        $estimate = $this->estimator()->estimate(
            '', '1024x1024', 'low', 0, $this->docsRates(), $this->docsTokenTable(),
        );

        $this->assertSame(0, $estimate->minTokens->text);
        $this->assertSame(0, $estimate->maxTokens->text);
    }

    public function test_no_reference_images_means_no_image_tokens(): void
    {
        $estimate = $this->estimator()->estimate(
            'A cat', '1024x1024', 'low', 0, $this->docsRates(), $this->docsTokenTable(),
        );

        $this->assertSame(0, $estimate->minTokens->image);
        $this->assertSame(0, $estimate->maxTokens->image);
    }

    public function test_negative_reference_image_count_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->estimator()->estimate('A cat', '1024x1024', 'low', -1, $this->docsRates(), $this->docsTokenTable());
    }

    public function test_unknown_quality_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->estimator()->estimate('A cat', '1024x1024', 'ultra', 0, $this->docsRates(), $this->docsTokenTable());
    }
}
