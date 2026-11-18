<?php

namespace Tests\Feature\Generations;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** The docs' GET /v1/pricing answer (docs/pixelforge-api.md, "Pricing and token usage"). */
trait PricingFake
{
    /** @return array<string, mixed> */
    private function pricingBody(): array
    {
        return [
            'object' => 'pricing',
            'currency' => 'usd',
            'unit' => 'per_1m_tokens',
            'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
            'tokens' => [
                'characters_per_text_token' => 4,
                'text_token_variance' => 0.15,
                'per_reference_image' => 765,
                'output' => [
                    'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                    'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                    'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
                ],
                'reasoning' => [
                    'low' => ['min' => 0, 'max' => 500],
                    'medium' => ['min' => 200, 'max' => 2000],
                    'high' => ['min' => 1000, 'max' => 8000],
                ],
            ],
        ];
    }

    private function fakePricing(): void
    {
        Http::fake(['*/pricing' => Http::response($this->pricingBody(), 200)]);
    }

    private function pricingRequestCount(): int
    {
        return Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/pricing'))->count();
    }
}
