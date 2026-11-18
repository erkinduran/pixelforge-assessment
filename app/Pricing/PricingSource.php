<?php

namespace App\Pricing;

use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use App\Support\InvalidMoneyException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The current rates and token table, from GET /v1/pricing, cached for a few minutes.
 * Only an answer we could parse is cached. If there is no usable answer we throw
 * PricingUnavailableException: no stale guess, no hardcoded fallback.
 */
class PricingSource
{
    private const CACHE_KEY = 'pixelforge.pricing';

    private const CACHE_SECONDS = 300;

    public function __construct(private readonly PixelforgeClient $client) {}

    /** @throws PricingUnavailableException */
    public function get(): Pricing
    {
        try {
            $raw = Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, function (): array {
                $data = $this->client->pricing();
                $this->parse($data); // throws before anything bad is cached

                return $data;
            });

            return $this->parse($raw);
        } catch (PixelforgeException|InvalidArgumentException|InvalidMoneyException $e) {
            Log::warning('Pixelforge pricing is unavailable: '.$e->getMessage());

            throw new PricingUnavailableException('Pixelforge pricing is unavailable.', previous: $e);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function parse(array $data): Pricing
    {
        if (! is_array($data['rates'] ?? null) || ! is_array($data['tokens'] ?? null)) {
            throw new InvalidArgumentException('Pricing has no "rates" or "tokens" object');
        }

        $pricing = new Pricing(Rates::fromPixelforge($data['rates']), TokenTable::fromPricing($data['tokens']));

        if ($pricing->table->qualities() === [] || $pricing->table->sizes() === []) {
            throw new InvalidArgumentException('Pricing has no sizes or qualities to choose from');
        }

        return $pricing;
    }
}
