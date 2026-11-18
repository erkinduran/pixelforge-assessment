<?php

namespace Tests\Unit\Pricing;

use App\Pricing\Rates;
use App\Pricing\TokenTable;

/** The GET /v1/pricing example response from the Pixelforge docs ("Pricing and token usage"), copied as a JSON file. */
trait PricingFixture
{
    /** @return array<string, mixed> */
    private function pricingResponse(): array
    {
        $json = file_get_contents(__DIR__.'/fixtures/pricing.json');

        return json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
    }

    private function docsRates(): Rates
    {
        return Rates::fromPixelforge($this->pricingResponse()['rates']);
    }

    private function docsTokenTable(): TokenTable
    {
        return TokenTable::fromPricing($this->pricingResponse()['tokens']);
    }
}
