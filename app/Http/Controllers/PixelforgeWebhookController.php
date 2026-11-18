<?php

namespace App\Http\Controllers;

use App\Generations\ApplyPixelforgeGeneration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Receives Pixelforge webhooks. Delivery is at least once and in any order (docs L496-499),
 * so every event is checked for a valid signature, recorded once, and then applied.
 */
final class PixelforgeWebhookController
{
    // The docs' example code rejects timestamps more than 300 seconds away from now.
    private const MAX_AGE_SECONDS = 300;

    public function __invoke(Request $request, ApplyPixelforgeGeneration $apply): JsonResponse
    {
        $secret = config('services.pixelforge.webhook_secret');

        // With an empty secret anyone could sign a request, so refuse to run without one.
        if (! is_string($secret) || $secret === '') {
            Log::error('Pixelforge webhook secret is not configured');

            return response()->json(['error' => 'Webhook is not configured.'], 500);
        }

        // The signature covers the raw bytes, so verify before decoding anything.
        $rawBody = $request->getContent();

        if (! $this->signatureIsValid($rawBody, (string) $request->header('Pixelforge-Signature'), $secret)) {
            // 400 is what the docs' example answers. Nothing is written.
            return response()->json(['error' => 'Invalid Pixelforge signature.'], 400);
        }

        $event = json_decode($rawBody, true);

        if (! is_array($event)) {
            // Never log the body: it holds a signed image URL.
            Log::error('Pixelforge webhook: signed body is not a JSON object');

            return response()->json(['error' => 'Body is not a JSON object.'], 400);
        }

        $type = $event['type'] ?? null;

        // ping, and any event type we do not know: acknowledge so it is not retried, record nothing.
        if (! is_string($type) || ! in_array($type, ['generation.processing', 'generation.succeeded', 'generation.failed'], true)) {
            return response()->json(['ok' => true]);
        }

        $eventId = $event['id'] ?? null;
        $object = $event['data']['object'] ?? null;

        if (! is_string($eventId) || $eventId === '' || ! is_array($object)) {
            Log::error('Pixelforge webhook: event has no id or data.object', [
                'event_id' => is_string($eventId) ? $eventId : null,
                'type' => $type,
            ]);

            return response()->json(['error' => 'Event has no id or data.object.'], 400);
        }

        try {
            // One transaction: the event row and the generation change are saved together or not at all.
            DB::transaction(function () use ($apply, $eventId, $type, $object): void {
                // Dedupe on the id inside the signed body (the Pixelforge-Event-Id header is not signed).
                // ON CONFLICT DO NOTHING: 0 rows means this event was already processed.
                $inserted = DB::table('webhook_events')->insertOrIgnore([
                    'event_id' => $eventId,
                    'type' => $type,
                    'generation_pixelforge_id' => is_string($object['id'] ?? null) ? $object['id'] : null,
                    'received_at' => now(),
                ]);

                if ($inserted === 0) {
                    return;
                }

                if ($apply->apply($object) === null) {
                    // Not ours (another environment, or a row we never created). Keep the event row
                    // so a redelivery is skipped, and answer 200 so Pixelforge stops retrying.
                    Log::warning("Pixelforge event {$eventId} is for a generation we do not know");
                }
            });
        } catch (Throwable $e) {
            // The transaction rolled back, event row included. A 5xx makes Pixelforge redeliver.
            report($e);

            return response()->json(['error' => 'Could not process the event.'], 500);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * The header looks like "t=<unix time>,v1=<hex hmac>"; the hmac is over "<t>.<raw body>" (docs L451-458).
     * Parsed like the docs' example (L466-473): key=value pairs, any order, unknown keys ignored.
     */
    private function signatureIsValid(string $rawBody, string $header, string $secret): bool
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $key = trim($key);
            $value = trim($value);

            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1' && preg_match('/^[0-9a-fA-F]{64}$/', $value) === 1) {
                // Several v1 values can be present; a malformed one is just not a candidate.
                $signatures[] = strtolower($value);
            }
        }

        if ($timestamp === null || ! ctype_digit($timestamp) || $signatures === []) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        // hash_equals compares in constant time, so the response time leaks nothing about the signature.
        $matches = false;
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                $matches = true;
            }
        }

        if (! $matches) {
            return false;
        }

        // Both directions: an old timestamp is a replay, a far-future one is a wrong clock or a forgery.
        return abs(now()->getTimestamp() - (int) $timestamp) <= self::MAX_AGE_SECONDS;
    }
}
