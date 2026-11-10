<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\PlaybackSession;
use App\Models\User;
use App\Models\VideoSource;
use App\Support\Contracts\ExistingCdnAuthorizer;
use App\Support\Playback\ExistingCdnPlaybackSigner;
use App\Support\Playback\SignedPlaybackUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\Concerns\ApiHelpers;
use Tests\TestCase;

class ExistingCdnIntegrationTest extends TestCase
{
    use ApiHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hosts->set('cdn.goldendragon2000.com', ['93.184.216.34']);
        config(['ott.playback.signed_urls' => true, 'ott.playback.signer' => ExistingCdnPlaybackSigner::class,
            'ott.external_cdn.verified' => false, 'ott.external_cdn.authorizer' => null]);
    }

    private function catalogSource(): Movie
    {
        $movie = Movie::factory()->create();
        VideoSource::factory()->for($movie)->create(['url' => 'https://cdn.goldendragon2000.com/vod/authorized/master.m3u8']);

        return $movie;
    }

    private function viewer(): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken(User::factory()->create()->createToken('web')->plainTextToken);
    }

    private function mockAuthorizer(string $case = 'valid'): void
    {
        $this->app->instance(ExistingCdnAuthorizer::class, new class($case) implements ExistingCdnAuthorizer
        {
            public function __construct(private string $case) {}

            public function authorize(VideoSource $source, PlaybackSession $session): SignedPlaybackUrl
            {
                // Mock TRANSPORT contract only: this is not the CDN's algorithm.
                $url = match ($this->case) {
                    'host' => 'https://other.example.test/master.m3u8?fixture=1',
                    'path' => 'https://cdn.goldendragon2000.com/another/master.m3u8?fixture=1',
                    'unsigned' => $source->url,
                    default => $source->url.'?fixture_session='.$session->public_id.'&fixture_time='.now()->timestamp,
                };

                return new SignedPlaybackUrl($url,
                    $this->case === 'expired' ? now()->subMinute() : ($this->case === 'extended' ? now()->addDay() : now()->addSeconds(120)),
                    $this->case === 'cookie' ? Cookie::create('invalid-cross-domain', 'test', 0, '/', 'goldendragon2000.com', true, true) : null);
            }
        });
    }

    public function test_cms_source_flows_to_existing_host_without_remapping_and_renews(): void
    {
        $admin = User::factory()->administrator()->create();
        $url = 'https://cdn.goldendragon2000.com/vod/authorized/master.m3u8';
        $created = $this->withToken($this->adminToken($admin))->postJson('/api/v1/admin/movies', $this->moviePayload([
            'sources' => [$this->source(['url' => $url])],
        ]))->assertCreated()->assertJsonPath('data.sources.0.url', $url);
        $id = $created->json('data.id');
        $this->viewer();
        $this->postJson('/api/v1/playback/sessions', ['movie_id' => $id])->assertStatus(503);
        config(['ott.external_cdn.verified' => true]);
        $this->mockAuthorizer();
        $play = $this->postJson('/api/v1/playback/sessions', ['movie_id' => $id])->assertCreated()
            ->assertJsonPath('data.signature_required', true)->assertJsonPath('data.credentials_required', false);
        $this->assertSame(parse_url($url, PHP_URL_HOST), parse_url($play->json('data.url'), PHP_URL_HOST));
        $this->assertSame(parse_url($url, PHP_URL_PATH), parse_url($play->json('data.url'), PHP_URL_PATH));
        $this->assertCount(0, $play->headers->getCookies());
        $this->assertGreaterThan(0, $play->json('data.renew_after_seconds'));
        $this->travel(10)->seconds();
        $renew = $this->postJson('/api/v1/playback/sessions/'.$play->json('data.session_id').'/renew')->assertOk();
        $this->assertNotSame($play->json('data.url'), $renew->json('data.url'));
        $this->assertDatabaseCount('playback_sessions', 1);
    }

    public function test_unknown_protocol_is_blocked_even_when_verified_flag_is_true(): void
    {
        $movie = $this->catalogSource();
        $this->viewer();
        config(['ott.external_cdn.verified' => true]);
        $this->postJson('/api/v1/playback/sessions', ['movie_id' => $movie->id])->assertStatus(503);
        $this->assertDatabaseCount('playback_sessions', 0);
    }

    public function test_production_keeps_verification_gate_when_unsigned_is_requested(): void
    {
        $movie = $this->catalogSource();
        $this->viewer();
        $this->mockAuthorizer();
        $this->app->instance('env', 'production');
        config(['ott.playback.signed_urls' => false]);
        $this->postJson('/api/v1/playback/sessions', ['movie_id' => $movie->id])->assertStatus(503);
        $this->assertDatabaseCount('playback_sessions', 0);
    }

    public static function invalidAuthorizations(): array
    {
        return [['host'], ['path'], ['unsigned'], ['expired'], ['extended'], ['cookie']];
    }

    #[DataProvider('invalidAuthorizations')]
    public function test_invalid_adapters_cannot_change_host_path_or_bypass_access_control(string $case): void
    {
        $movie = $this->catalogSource();
        $this->viewer();
        $this->mockAuthorizer($case);
        config(['ott.external_cdn.verified' => true]);
        $response = $this->postJson('/api/v1/playback/sessions', ['movie_id' => $movie->id])->assertStatus(503);
        $this->assertCount(0, $response->headers->getCookies());
        $this->assertDatabaseCount('playback_sessions', 0);
    }
}
