<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CredentialedCorsTest extends TestCase
{
    public static function origins(): array
    {
        return [['https://ott.zentunnel.net'], ['https://cms.zentunnel.net']];
    }

    #[DataProvider('origins')]
    public function test_preflight_uses_exact_origin_credentials_and_required_headers(string $origin): void
    {
        $response = $this->options('/api/v1/playback/sessions', [], ['Origin' => $origin,
            'Access-Control-Request-Method' => 'POST', 'Access-Control-Request-Headers' => 'Authorization,Content-Type']);
        $response->assertNoContent()->assertHeader('Access-Control-Allow-Origin', $origin)
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
        $this->assertStringContainsString('POST', $response->headers->get('Access-Control-Allow-Methods'));
        $allowed = strtolower($response->headers->get('Access-Control-Allow-Headers'));
        $this->assertStringContainsString('authorization', $allowed);
        $this->assertStringContainsString('content-type', $allowed);
        $this->assertCount(0, $response->headers->getCookies());
    }

    #[DataProvider('origins')]
    public function test_real_401_and_422_responses_keep_cors_and_no_login_cookies(string $origin): void
    {
        $this->get('/api/v1/me', ['Origin' => $origin])->assertUnauthorized()
            ->assertHeader('Access-Control-Allow-Origin', $origin)->assertHeader('Access-Control-Allow-Credentials', 'true');
        $response = $this->call('POST', '/api/v1/auth/login', [], [], [], ['HTTP_ORIGIN' => $origin, 'CONTENT_TYPE' => 'application/json'], '{}');
        $response->assertUnprocessable()->assertHeader('Access-Control-Allow-Origin', $origin)->assertHeader('Access-Control-Allow-Credentials', 'true');
        $this->assertCount(0, $response->headers->getCookies());
        $this->assertStringNotContainsString('token', strtolower($response->headers->get('Access-Control-Expose-Headers', '')));
    }

    public function test_forbidden_and_server_errors_preserve_credentialed_cors(): void
    {
        Route::middleware('api')->get('/api/_test/cors-forbidden', function () {
            abort(403);
        });
        Route::middleware('api')->get('/api/_test/cors-error', function () {
            throw new \RuntimeException('fixture-only');
        });
        foreach (['https://ott.zentunnel.net', 'https://cms.zentunnel.net'] as $origin) {
            foreach (['/api/_test/cors-forbidden' => 403, '/api/_test/cors-error' => 500] as $path => $status) {
                $this->get($path, ['Origin' => $origin])->assertStatus($status)
                    ->assertHeader('Access-Control-Allow-Origin', $origin)->assertHeader('Access-Control-Allow-Credentials', 'true');
            }
        }
    }

    public function test_hostile_origins_and_suffix_tricks_are_not_allowed(): void
    {
        foreach (['https://evil.example', 'https://ott.zentunnel.net.evil.example', 'null'] as $origin) {
            $response = $this->options('/api/v1/me', [], ['Origin' => $origin, 'Access-Control-Request-Method' => 'GET']);
            $this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));
        }
    }

    public function test_wildcard_configuration_is_filtered_out(): void
    {
        $old = getenv('CORS_ALLOWED_ORIGINS');
        putenv('CORS_ALLOWED_ORIGINS=*,https://*.example.com,https://ott.zentunnel.net');
        try {
            $config = require base_path('config/cors.php');
            $this->assertSame(['https://ott.zentunnel.net'], $config['allowed_origins']);
            $this->assertSame([], $config['allowed_origins_patterns']);
        } finally {
            $old === false ? putenv('CORS_ALLOWED_ORIGINS') : putenv('CORS_ALLOWED_ORIGINS='.$old);
        }
    }
}
