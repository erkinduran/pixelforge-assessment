<?php

namespace App\Pricing;

/** What GET /v1/pricing says, parsed: the rates and the token table. */
final class Pricing
{
    public function __construct(
        public readonly Rates $rates,
        public readonly TokenTable $table,
    ) {}
}
