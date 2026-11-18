<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A route in the web group, so it passes through the same middleware as the real pages.
        Route::middleware('web')->get('/_proxy-check', fn (Request $request) => response()->json([
            'secure' => $request->isSecure(),
            'url' => url('/somewhere'),
            'client_ip' => $request->ip(),
        ]));
    }

    /** @return array<string, array{string}> */
    public static function trustedProxyAddresses(): array
    {
        return [
            'the host itself' => ['127.0.0.1'],
            'docker bridge 172.18' => ['172.18.0.1'],
            'docker default network 172.17' => ['172.17.0.1'],
            'private 10.x' => ['10.1.2.3'],
            'private 192.168.x' => ['192.168.16.1'],
        ];
    }

    #[DataProvider('trustedProxyAddresses')]
    public function test_a_trusted_proxy_makes_https_links_and_passes_the_client_ip(string $proxy): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => $proxy])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.7'])
            ->getJson('/_proxy-check');

        $response->assertOk()->assertJson(['secure' => true, 'client_ip' => '203.0.113.7']);
        $this->assertStringStartsWith('https://', $response->json('url'));
    }

    public function test_without_the_header_links_stay_http(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.1'])->getJson('/_proxy-check');

        $response->assertJson(['secure' => false]);
        $this->assertStringStartsWith('http://', $response->json('url'));
    }

    public function test_a_client_on_the_internet_cannot_forge_the_forwarded_headers(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '198.51.100.1'])
            ->getJson('/_proxy-check');

        $response->assertJson(['secure' => false, 'client_ip' => '203.0.113.9']);
        $this->assertStringStartsWith('http://', $response->json('url'));
    }
}
