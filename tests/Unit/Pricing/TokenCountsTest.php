<?php

namespace Tests\Unit\Pricing;

use App\Pricing\TokenCounts;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TokenCountsTest extends TestCase
{
    public function test_usage_object_becomes_token_counts(): void
    {
        $counts = TokenCounts::fromPixelforge([
            'input_text_tokens' => 12,
            'input_image_tokens' => 765,
            'output_tokens' => 6450,
            'reasoning_tokens' => 3184,
            'total_tokens' => 10411,
        ]);

        $this->assertSame([12, 765, 6450, 3184], [$counts->text, $counts->image, $counts->output, $counts->reasoning]);
    }

    public function test_negative_token_counts_throw(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TokenCounts(text: -1, image: 0, output: 0, reasoning: 0);
    }

    public function test_usage_with_a_missing_field_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TokenCounts::fromPixelforge(['input_text_tokens' => 12]);
    }
}
