<?php

namespace App\Pricing;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Pixelforge's pricing could not be fetched or understood. Without it there is no estimate and
 * no submit. It renders itself: 503 JSON for the estimate call, a form error for a page post.
 */
class PricingUnavailableException extends RuntimeException
{
    public const USER_MESSAGE = 'Pricing is unavailable right now, so we cannot show an estimate or submit. Please try again in a moment.';

    /**
     * PricingSource already logged a warning with the cause. Returning anything but false tells
     * Laravel the exception is handled, so it is not logged a second time as an error.
     */
    public function report(): bool
    {
        return true;
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => self::USER_MESSAGE], 503);
        }

        return back()->withErrors(['pricing' => self::USER_MESSAGE]);
    }
}
