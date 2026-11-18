<?php

namespace App\Pixelforge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around the Pixelforge HTTP API. It sends one request per call and throws
 * PixelforgeException on any failure. It never retries: that is the caller's job.
 */
class PixelforgeClient
{
    public const PRICING_TIMEOUT_SECONDS = 3;

    public const PRICING_CONNECT_TIMEOUT_SECONDS = 2;

    /**
     * POST /generations. Pass the same $idempotencyKey on every retry of the same generation.
     *
     * `replayed` is true when Pixelforge already knew the key and returned the existing generation
     * (header `Idempotent-Replayed: true`) instead of creating a new one.
     *
     * @param  array<string, mixed>  $body
     * @return array{generation: array<string, mixed>, replayed: bool}
     */
    public function createGeneration(array $body, string $idempotencyKey): array
    {
        $response = $this->send(fn (PendingRequest $request) => $request
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/generations', $body));

        return [
            'generation' => $this->decode($response),
            'replayed' => $response->header('Idempotent-Replayed') === 'true',
        ];
    }

    /**
     * GET /generations/{id}.
     *
     * @return array<string, mixed>
     */
    public function getGeneration(string $id): array
    {
        return $this->decode($this->send(fn (PendingRequest $request) => $request->get('/generations/'.$id)));
    }

    /**
     * GET /pricing. Docs: needs no authentication, so the bearer token is not sent.
     *
     * @return array<string, mixed>
     */
    public function pricing(): array
    {
        // Short timeouts: the generate form asks for pricing while the user types. An outage must
        // not hold a PHP worker for the 15 s a create call may take.
        return $this->decode($this->send(
            fn (PendingRequest $request) => $request->get('/pricing'),
            withToken: false,
            timeout: self::PRICING_TIMEOUT_SECONDS,
            connectTimeout: self::PRICING_CONNECT_TIMEOUT_SECONDS,
        ));
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @param  int|null  $timeout  Total seconds; null = the configured one.
     */
    private function send(callable $call, bool $withToken = true, ?int $timeout = null, int $connectTimeout = 5): Response
    {
        $request = Http::baseUrl(config('services.pixelforge.base_url'))
            ->acceptJson()
            ->connectTimeout($connectTimeout)
            ->timeout($timeout ?? config('services.pixelforge.timeout'));

        if ($withToken) {
            $request = $request->withToken((string) config('services.pixelforge.key'));
        }

        try {
            $response = $call($request);
        } catch (ConnectionException $e) {
            throw PixelforgeException::fromConnection($e);
        }

        if ($response->failed()) {
            throw PixelforgeException::fromResponse($response);
        }

        return $response;
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        $data = $response->json();

        if (! is_array($data)) {
            // A 2xx that is not JSON (broken proxy?). Status null means "no usable answer" -> retry.
            throw new PixelforgeException('Pixelforge returned a body that is not JSON.');
        }

        return $data;
    }
}
