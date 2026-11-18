<?php

namespace App\Http\Resources;

use App\Models\Generation;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the browser may know about a generation. Money is only ever a formatted string.
 * There is deliberately no provider cost, no markup, no rates and no Pixelforge id here.
 * Load the `charge` relation first (the final price comes from it).
 */
class GenerationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Generation $generation */
        $generation = $this->resource;

        // The signed image link stops working at image_url_expires_at. An unknown expiry counts as
        // expired, and so does anything that is not an https link (it goes into an <a href> and <img src>).
        $hasImage = $generation->status === Generation::SUCCEEDED && $generation->image_url !== null;
        $imageExpired = $hasImage
            && (! str_starts_with((string) $generation->image_url, 'https://')
                || $generation->image_url_expires_at === null
                || $generation->image_url_expires_at->isPast());

        // The price the user pays exists only on the charge, and only for a succeeded generation.
        $charge = $generation->status === Generation::SUCCEEDED ? $generation->charge : null;

        return [
            'id' => $generation->id,
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'reference_image_urls' => $generation->reference_image_urls,
            'status' => $generation->status,
            'created_at' => $generation->created_at?->toIso8601String(),
            'completed_at' => $generation->completed_at?->toIso8601String(),
            'estimate' => [
                'min' => Money::formatUsd($generation->estimate_min_pico),
                'max' => Money::formatUsd($generation->estimate_max_pico),
            ],
            'price' => $charge === null ? null : Money::formatUsd($charge->user_price_pico),
            'failure_message' => $generation->status === Generation::FAILED ? $generation->failure_message : null,
            'image_url' => $hasImage && ! $imageExpired ? $generation->image_url : null,
            'image_expired' => $imageExpired,
        ];
    }
}
