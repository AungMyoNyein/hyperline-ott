<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\Movie;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\ApiHelpers;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    use ApiHelpers;
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->administrator()->create([
            'email' => 'admin@hyperline.test',
            'password' => 'Super-Secret-Pass-1',
        ]);
    }

    public function test_administrator_can_sign_in_and_use_the_token(): void
    {
        $this->admin();

        $response = $this->postJson('/api/v1/admin/login', [
            'email' => 'admin@hyperline.test',
            'password' => 'Super-Secret-Pass-1',
            'device_name' => 'cms',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.email', 'admin@hyperline.test')
            ->assertJsonPath('data.user.roles.0', 'admin');

        $this->assertNotEmpty($response->json('data.token'));

        $this->withHeaders($this->bearer($response->json('data.token')))
            ->getJson('/api/v1/admin/movies')
            ->assertOk();
    }

    public function test_password_is_hashed_and_never_returned(): void
    {
        $admin = $this->admin();

        $this->assertHashed((string) $admin->password);
        $this->assertStringNotContainsString('Super-Secret-Pass-1', $admin->password);

        $this->withHeaders($this->bearer($this->adminToken($admin)))
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'admin@hyperline.test')
            ->assertJsonMissingPath('data.password');
    }

    public function test_wrong_password_is_rejected_without_confirming_the_account(): void
    {
        $this->admin();

        $this->postJson('/api/v1/admin/login', [
            'email' => 'admin@hyperline.test',
            'password' => 'not-the-password',
        ])->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');

        // Same answer for an unknown address, so the endpoint cannot enumerate admins.
        $this->postJson('/api/v1/admin/login', [
            'email' => 'ghost@hyperline.test',
            'password' => 'not-the-password',
        ])->assertStatus(401)->assertJsonPath('message', 'These credentials do not match our records.');
    }

    public function test_non_administrator_cannot_sign_in_to_the_cms(): void
    {
        User::factory()->subscriber()->create([
            'email' => 'viewer@hyperline.test',
            'password' => 'Viewer-Secret-1',
        ]);

        $this->postJson('/api/v1/admin/login', [
            'email' => 'viewer@hyperline.test',
            'password' => 'Viewer-Secret-1',
        ])->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');

        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'admin.login_denied']);
    }

    public function test_sign_in_is_rate_limited(): void
    {
        $this->admin();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/admin/login', [
                'email' => 'admin@hyperline.test',
                'password' => 'wrong',
            ])->assertStatus(401);
        }

        $this->postJson('/api/v1/admin/login', [
            'email' => 'admin@hyperline.test',
            'password' => 'Super-Secret-Pass-1',
        ])->assertStatus(429)->assertJsonPath('code', 'rate_limited');
    }

    public function test_admin_endpoints_require_authentication(): void
    {
        foreach (['/api/v1/admin/dashboard', '/api/v1/admin/movies', '/api/v1/admin/audit-logs'] as $url) {
            $this->getJson($url)->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        }
    }

    public function test_signed_in_subscribers_cannot_reach_admin_endpoints(): void
    {
        $viewer = User::factory()->subscriber()->create();
        $token = $viewer->createToken('web')->plainTextToken;

        // A real id, because route binding runs before the role check and would
        // otherwise answer 404 and hide the authorisation result.
        $movie = Movie::factory()->create();

        $this->withHeaders($this->bearer($token))->getJson('/api/v1/admin/dashboard')
            ->assertStatus(403)->assertJsonPath('code', 'forbidden');

        $this->withHeaders($this->bearer($token))->getJson('/api/v1/admin/movies')
            ->assertStatus(403);

        $this->withHeaders($this->bearer($token))->postJson('/api/v1/admin/movies', $this->moviePayload())
            ->assertStatus(403);

        $this->withHeaders($this->bearer($token))->putJson('/api/v1/admin/movies/'.$movie->id, ['title' => 'Hijacked'])
            ->assertStatus(403);

        $this->withHeaders($this->bearer($token))->deleteJson('/api/v1/admin/movies/'.$movie->id)
            ->assertStatus(403);

        $this->assertDatabaseHas('movies', ['id' => $movie->id, 'title' => $movie->title]);
    }

    public function test_viewer_endpoints_do_not_require_the_admin_role(): void
    {
        $viewer = User::factory()->subscriber()->create();
        $token = $viewer->createToken('web')->plainTextToken;

        $this->withHeaders($this->bearer($token))->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.roles.0', 'subscriber');
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $admin = $this->admin();
        $token = $this->adminToken($admin);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/v1/admin/logout')
            ->assertOk()
            ->assertJson(['status' => 'logged_out']);

        $this->assertSame(0, PersonalAccessToken::query()->count());
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'admin.logout', 'user_id' => $admin->id]);

        // The application instance is reused across requests inside one test, so the
        // resolved guard has to be dropped or the deleted token would still authenticate.
        $this->app['auth']->forgetGuards();

        $this->withHeaders($this->bearer($token))->getJson('/api/v1/admin/dashboard')->assertStatus(401);
    }

    public function test_sign_in_and_crud_are_audited_with_the_actor(): void
    {
        $admin = $this->admin();
        $token = $this->adminToken($admin);

        $this->withHeaders($this->bearer($token))->postJson('/api/v1/admin/movies', $this->moviePayload())->assertCreated();

        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'movie.created', 'user_id' => $admin->id]);
        $this->assertSame(1, AdminAuditLog::query()->whereNotNull('ip_address')->count());
    }

    private function assertHashed(string $password): void
    {
        $this->assertMatchesRegularExpression('/^\$2y\$/', $password, 'Password must be bcrypt hashed.');
    }
}
