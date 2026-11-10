<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApiErrorContractTest extends TestCase
{
    use RefreshDatabase;

    public static function accepts(): array
    {
        return [[''], ['application/json'], ['text/html']];
    }

    #[DataProvider('accepts')]
    public function test_authentication_and_validation_stay_json(string $accept): void
    {
        $headers = $accept === '' ? [] : ['Accept' => $accept];
        $this->get('/api/v1/me', $headers)->assertUnauthorized()->assertJsonPath('code', 'unauthenticated')
            ->assertHeader('Content-Type', 'application/json');
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($accept !== '') {
            $server['HTTP_ACCEPT'] = $accept;
        }
        $this->call('POST', '/api/v1/auth/login', [], [], [], $server, '{}')
            ->assertUnprocessable()->assertJsonValidationErrors(['email', 'password'])->assertHeader('Content-Type', 'application/json');
    }

    #[DataProvider('accepts')]
    public function test_authorization_is_403_without_redirect_or_model_change(string $accept): void
    {
        $token = User::factory()->subscriber()->create()->createToken('test')->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token];
        if ($accept !== '') {
            $headers['Accept'] = $accept;
        }
        $this->get('/api/v1/admin/dashboard', $headers)->assertForbidden()->assertJsonPath('code', 'forbidden')
            ->assertHeader('Content-Type', 'application/json');
    }

    #[DataProvider('accepts')]
    public function test_unexpected_errors_are_opaque_even_in_debug_mode(string $accept): void
    {
        config(['app.debug' => true]);
        Route::middleware('api')->get('/api/_test/unexpected', function () {
            throw new \RuntimeException('fixture-password=do-not-expose DSN /private/path');
        });
        $response = $this->get('/api/_test/unexpected', $accept === '' ? [] : ['Accept' => $accept]);
        $response->assertStatus(500)->assertExactJson(['message' => 'Server error.', 'code' => 'server_error']);
        $this->assertStringNotContainsString('fixture-password', $response->getContent());
        $this->assertStringNotContainsString('trace', $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }
}
