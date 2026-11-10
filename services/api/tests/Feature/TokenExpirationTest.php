<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Authentication\IssueAccessToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class TokenExpirationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Isolated-Test-Password-2026!';

    public function test_login_and_registration_issue_absolute_expiry_metadata(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->assertSame(1440, config('sanctum.expiration'));
        $admin = User::factory()->administrator()->create(['password' => self::PASSWORD]);
        $viewer = User::factory()->subscriber()->create(['password' => self::PASSWORD]);
        foreach ([['/api/v1/admin/login', $admin], ['/api/v1/auth/login', $viewer]] as [$path, $user]) {
            $response = $this->postJson($path, ['email' => $user->email, 'password' => self::PASSWORD])->assertOk();
            $token = PersonalAccessToken::findToken($response->json('data.token'));
            $this->assertTrue($token->expires_at->equalTo(now()->addDay()));
            $response->assertJsonPath('data.expires_at', $token->expires_at->toISOString());
        }
        $response = $this->postJson('/api/v1/auth/register', ['name' => 'Expiry fixture', 'email' => 'expiry@hyperline.test', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertCreated();
        $this->assertTrue(PersonalAccessToken::findToken($response->json('data.token'))->expires_at->equalTo(now()->addDay()));
    }

    public function test_valid_token_never_slides_and_is_rejected_after_deadline(): void
    {
        $this->travelTo(now()->startOfSecond());
        $token = app(IssueAccessToken::class)->issue(User::factory()->subscriber()->create(), 'expiry-fixture');
        $deadline = $token->accessToken->expires_at->copy();
        $this->travel(23)->hours();
        $this->asToken($token->plainTextToken)->getJson('/api/v1/me')->assertOk();
        $this->assertTrue($token->accessToken->fresh()->expires_at->equalTo($deadline));
        $this->travel(61)->minutes();
        $this->asToken($token->plainTextToken)->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->assertTrue($token->accessToken->fresh()->expires_at->equalTo($deadline));
    }

    public function test_legacy_token_without_expiry_is_bounded_by_creation_time(): void
    {
        $this->travelTo(now()->startOfSecond());
        $token = User::factory()->create()->createToken('legacy-fixture');
        $this->assertNull($token->accessToken->expires_at);
        $this->travel(24)->hours();
        $this->asToken($token->plainTextToken)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_configurable_lifetime_cannot_extend_an_issued_tokens_deadline(): void
    {
        $this->travelTo(now()->startOfSecond());
        config(['sanctum.expiration' => 60]);
        $token = app(IssueAccessToken::class)->issue(User::factory()->create(), 'short-fixture');
        $this->assertTrue($token->accessToken->expires_at->equalTo(now()->addHour()));
        $this->travel(61)->minutes();
        config(['sanctum.expiration' => 2880]);
        $this->asToken($token->plainTextToken)->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertTrue($token->accessToken->fresh()->expires_at->isPast());
    }

    public function test_revoked_token_is_rejected(): void
    {
        $token = app(IssueAccessToken::class)->issue(User::factory()->create(), 'revoked-fixture');
        $token->accessToken->delete();
        $this->asToken($token->plainTextToken)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_admin_and_viewer_logout_revoke_only_current_token(): void
    {
        foreach ([['administrator', '/api/v1/admin/logout'], ['subscriber', '/api/v1/auth/logout']] as [$role, $path]) {
            $user = User::factory()->{$role}()->create();
            $current = app(IssueAccessToken::class)->issue($user, 'current-fixture');
            $other = app(IssueAccessToken::class)->issue($user, 'other-fixture');
            $this->asToken($current->plainTextToken)->postJson($path)->assertOk()->assertJsonPath('status', 'logged_out');
            $this->assertNull(PersonalAccessToken::findToken($current->plainTextToken));
            $this->asToken($current->plainTextToken)->getJson('/api/v1/me')->assertUnauthorized();
            $this->asToken($other->plainTextToken)->getJson('/api/v1/me')->assertOk();
        }
    }

    public function test_expired_logout_cannot_extend_or_reissue_token(): void
    {
        $this->travelTo(now()->startOfSecond());
        $token = app(IssueAccessToken::class)->issue(User::factory()->administrator()->create(), 'expired-fixture');
        $this->travel(25)->hours();
        $this->asToken($token->plainTextToken)->postJson('/api/v1/admin/logout')->assertUnauthorized();
        $this->assertSame(1, PersonalAccessToken::count());
        $this->assertTrue($token->accessToken->fresh()->expires_at->isPast());
    }

    public function test_web_session_cannot_replace_bearer_token(): void
    {
        $this->travelTo(now()->startOfSecond());
        $admin = User::factory()->administrator()->create();
        $token = app(IssueAccessToken::class)->issue($admin, 'cookie-bypass-fixture');
        $this->actingAs($admin, 'web');
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->getJson('/api/v1/admin/dashboard')->assertUnauthorized();
        $this->travel(25)->hours();
        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/admin/dashboard')->assertUnauthorized();
    }

    public function test_issuer_fails_closed_if_runtime_policy_is_disabled(): void
    {
        config(['sanctum.expiration' => 0]);
        try {
            app(IssueAccessToken::class)->issue(User::factory()->create(), 'invalid-policy-fixture');
            $this->fail('Invalid policy must not issue a token.');
        } catch (\LogicException) {
            $this->assertSame(0, PersonalAccessToken::count());
        }
    }

    public function test_configuration_is_bounded_and_runtime_issuer_rejects_excessive_lifetimes(): void
    {
        $env = \Illuminate\Support\Env::getRepository();
        $previous = $env->get('SANCTUM_TOKEN_EXPIRATION');
        try {
            foreach (['0' => 1, '-5' => 1, 'nonsense' => 1, '99999999' => 10080, '60' => 60] as $input => $expected) {
                $env->set('SANCTUM_TOKEN_EXPIRATION', (string) $input);
                $this->assertSame($expected, (require config_path('sanctum.php'))['expiration']);
            }
        } finally {
            $previous === null ? $env->clear('SANCTUM_TOKEN_EXPIRATION') : $env->set('SANCTUM_TOKEN_EXPIRATION', $previous);
        }
        config(['sanctum.expiration' => 10081]);
        $this->expectException(\LogicException::class);
        app(IssueAccessToken::class)->issue(User::factory()->create(), 'excessive-fixture');
    }

    public function test_role_checks_and_unauthorized_access_with_expiring_tokens(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $viewer = app(IssueAccessToken::class)->issue(User::factory()->subscriber()->create(), 'viewer-fixture');
        $this->asToken($viewer->plainTextToken)->getJson('/api/v1/me')->assertOk();
        $this->asToken($viewer->plainTextToken)->getJson('/api/v1/admin/dashboard')->assertForbidden();
        $admin = app(IssueAccessToken::class)->issue(User::factory()->administrator()->create(), 'admin-fixture', ['cms']);
        $this->asToken($admin->plainTextToken)->getJson('/api/v1/admin/dashboard')->assertOk();
    }

    private function asToken(string $token): static
    {
        $this->app['auth']->forgetGuards();
        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
