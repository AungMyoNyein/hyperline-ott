<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\PlaybackSession;
use App\Models\User;
use App\Models\VideoSource;
use App\Support\Playback\NginxSecureLinkSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NginxPlaybackTest extends TestCase
{
    use RefreshDatabase;

    private string $registry;

    private Movie $movie;

    private VideoSource $source;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withServerVariables(['HTTPS' => 'on']);
        $this->registry = tempnam('/tmp', 'hl-assets-');
        $this->movie = Movie::factory()->create();
        $this->source = VideoSource::factory()->for($this->movie)->create(['url' => 'https://cdn.goldendragon2000.com/vod/approved/master.m3u8']);
        $this->viewer = User::factory()->create();
        file_put_contents($this->registry, json_encode([$this->source->id => ['movie_id' => $this->movie->id, 'origin_directory' => '/vod/approved/', 'manifest' => 'master.m3u8']]));
        config(['ott.cdn.base_url' => 'https://cdn.example.test', 'ott.cdn.cookie_domain' => 'example.test', 'app.url' => 'https://api.example.test', 'ott.playback.signed_urls' => true, 'ott.playback.signer' => NginxSecureLinkSigner::class,
            'ott.cdn.verified' => true, 'ott.cdn.secret' => str_repeat('0123456789abcdef', 4), 'ott.cdn.assets_file' => $this->registry]);
        $this->withToken($this->viewer->createToken('web')->plainTextToken);
    }

    protected function tearDown(): void
    {
        unlink($this->registry);
        parent::tearDown();
    }

    private function start(): TestResponse
    {
        return $this->postJson('https://api.example.test/api/v1/playback/sessions', ['movie_id' => $this->movie->id]);
    }

    public function test_exact_byte_level_vector(): void
    {
        $this->assertSame('DNU1mQNfvcmaLXQM3d9k1Q', NginxSecureLinkSigner::digest(2000000000, '/hls/7/42/', '12345678-1234-4234-8234-123456789abc', str_repeat('0123456789abcdef', 4)));
    }

    public function test_issuance_sets_a_short_lived_secure_directory_cookie_only(): void
    {
        $response = $this->start()->assertCreated()->assertJsonPath('data.credentials_required', true);
        $scope = '/hls/'.$this->movie->id.'/'.$this->source->id.'/';
        $response->assertJsonPath('data.url', 'https://cdn.example.test'.$scope.'master.m3u8');
        $cookie = $response->getCookie(NginxSecureLinkSigner::COOKIE, false);
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertSame('example.test', $cookie->getDomain());
        $this->assertSame($scope, $cookie->getPath());
        $this->assertLessThanOrEqual(now()->timestamp + 300, $cookie->getExpiresTime());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString($cookie->getValue(), $response->getContent());
        $this->assertStringNotContainsString(config('ott.cdn.secret'), $response->getContent());
        $this->assertStringNotContainsString('goldendragon', $response->getContent());
        $this->assertGreaterThan(0, $response->json('data.renew_after_seconds'));
    }

    public static function badConfiguration(): array
    {
        return [['ott.cdn.verified', false], ['ott.cdn.secret', 'placeholder'], ['ott.cdn.cookie_domain', 'example.org'], ['ott.cdn.assets_file', '/tmp/not-a-hyperline-registry'], ['ott.cdn.cookie_ttl_seconds', 3600]];
    }

    #[DataProvider('badConfiguration')]
    public function test_unverified_or_invalid_configuration_fails_closed(string $key, mixed $value): void
    {
        config([$key => $value]);
        $this->start()->assertStatus(503)->assertCookieMissing(NginxSecureLinkSigner::COOKIE);
        $this->assertDatabaseCount('playback_sessions', 0);
    }

    public function test_http_and_unapproved_source_changes_are_rejected(): void
    {
        $this->withServerVariables(['HTTPS' => 'off']);
        $this->postJson('http://api.zentunnel.net/api/v1/playback/sessions', ['movie_id' => $this->movie->id])->assertStatus(503);
        $this->withServerVariables(['HTTPS' => 'on']);
        $this->source->update(['url' => 'https://cdn.goldendragon2000.com/vod/another/master.m3u8']);
        $this->start()->assertStatus(503);
        $this->assertDatabaseCount('playback_sessions', 0);
    }

    public function test_owner_can_renew_but_token_expiry_has_a_fixed_session_limit(): void
    {
        $start = $this->start()->assertCreated();
        $id = $start->json('data.session_id');
        $first = $start->getCookie(NginxSecureLinkSigner::COOKIE, false);
        $this->travel(100)->seconds();
        $renewed = $this->postJson('https://api.example.test/api/v1/playback/sessions/'.$id.'/renew')->assertOk();
        $cookie = $renewed->getCookie(NginxSecureLinkSigner::COOKIE, false);
        $this->assertGreaterThan($first->getExpiresTime(), $cookie->getExpiresTime());
        $this->assertSame($first->getPath(), $cookie->getPath());
        $this->assertNotSame($first->getValue(), $cookie->getValue());
        config(['ott.cdn.max_session_seconds' => 110]);
        $limited = $this->postJson('https://api.example.test/api/v1/playback/sessions/'.$id.'/renew')->assertOk();
        $session = PlaybackSession::firstOrFail();
        $this->assertLessThanOrEqual($session->started_at->timestamp + 110, $limited->getCookie(NginxSecureLinkSigner::COOKIE, false)->getExpiresTime());
    }

    public function test_renewal_rechecks_owner_and_bearer_authentication(): void
    {
        $id = $this->start()->assertCreated()->json('data.session_id');
        $this->app['auth']->forgetGuards();
        $other = User::factory()->create();
        $this->withToken($other->createToken('other')->plainTextToken)
            ->postJson('/api/v1/playback/sessions/'.$id.'/renew')->assertNotFound()->assertCookieMissing(NginxSecureLinkSigner::COOKIE);
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->postJson('https://api.example.test/api/v1/playback/sessions/'.$id.'/renew')->assertUnauthorized();
    }

    public static function denialChanges(): array
    {
        return [['expired'], ['ended'], ['unpublished'], ['disabled'], ['deleted'], ['subscription'], ['maximum'], ['unverified']];
    }

    #[DataProvider('denialChanges')]
    public function test_renewal_rechecks_current_authorization(string $change): void
    {
        $id = $this->start()->assertCreated()->json('data.session_id');
        match ($change) {
            'expired' => PlaybackSession::query()->update(['expires_at' => now()->subMinute()]),
            'ended' => PlaybackSession::query()->update(['status' => 'ended']),
            'unpublished' => $this->movie->update(['published' => false]),
            'disabled' => $this->source->update(['enabled' => false]),
            'deleted' => $this->source->delete(),
            'subscription' => $this->viewer->update(['subscription_expires_at' => now()->subMinute()]),
            'maximum' => config(['ott.cdn.max_session_seconds' => -1]),
            'unverified' => config(['ott.cdn.verified' => false]),
        };
        $this->app['auth']->forgetGuards();
        $this->postJson('https://api.example.test/api/v1/playback/sessions/'.$id.'/renew')->assertStatus($change === 'unverified' ? 503 : 403)
            ->assertCookieMissing(NginxSecureLinkSigner::COOKIE);
    }
}
