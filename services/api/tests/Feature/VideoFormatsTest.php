<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\PlaybackSession;
use App\Models\Season;
use App\Models\Series;
use App\Models\SeriesEpisode;
use App\Models\User;
use App\Models\VideoSource;
use App\Support\Contracts\PlaybackUrlSigner;
use App\Support\Playback\SignedPlaybackUrl;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ApiHelpers;
use Tests\TestCase;

/**
 * Extended source formats: DASH became a first-class playable transport and
 * YouTube links are accepted as embed sources that bypass URL signing while
 * staying behind the same entitlement gates.
 */
class VideoFormatsTest extends TestCase
{
    use ApiHelpers;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->administrator()->create();
        // The SSRF guard resolves hosts through the stub; give YouTube public IPs.
        foreach (['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtu.be', 'www.youtu.be'] as $host) {
            $this->hosts->set($host, ['142.250.190.46']);
        }
    }

    /** @return array<string, string> */
    private function auth(): array
    {
        return $this->bearer($this->adminToken($this->admin));
    }

    public function test_admin_can_store_youtube_watch_and_short_links(): void
    {
        foreach ([
            'https://www.youtube.com/watch?v=M7lc1UVf-VE',
            'https://youtu.be/M7lc1UVf-VE',
            'https://www.youtube.com/embed/M7lc1UVf-VE',
            'https://www.youtube.com/shorts/M7lc1UVf-VE',
            'https://m.youtube.com/watch?v=M7lc1UVf-VE&t=10',
            'https://www.youtube.com/live/M7lc1UVf-VE',
            'https://youtu.be/M7lc1UVf-VE?si=tracking',
        ] as $index => $url) {
            $movie = Movie::factory()->create(['slug' => "yt-movie-{$index}"]);
            $this->withHeaders($this->auth())->patchJson("/api/v1/admin/movies/{$movie->id}", [
                'sources' => [['label' => 'youtube', 'kind' => 'youtube', 'url' => $url, 'is_default' => true, 'enabled' => true]],
            ])->assertOk();
            $this->assertDatabaseHas('video_sources', ['movie_id' => $movie->id, 'kind' => 'youtube', 'url' => 'https://www.youtube.com/watch?v=M7lc1UVf-VE']);
        }
    }

    public function test_invalid_youtube_links_are_rejected(): void
    {
        $movie = Movie::factory()->create();

        foreach ([
            'http://www.youtube.com/watch?v=M7lc1UVf-VE',
            'https://user:pass@www.youtube.com/watch?v=M7lc1UVf-VE',
            'https://www.youtube.com:8443/watch?v=M7lc1UVf-VE',
            'https://www.youtube.com.evil.test/watch?v=M7lc1UVf-VE',
            'https://www.youtube.com/watch/other?v=M7lc1UVf-VE',
            'https://youtu.be/M7lc1UVf-VE/extra',
            'https://www.youtube.com/watch?v=M7lc1UVf-VE&v=abcdefghijk',
            'https://www.youtube.com/watch?v[]=M7lc1UVf-VE',
            'https://example.com/watch?v=M7lc1UVf-VE',      // wrong host
            'https://www.youtube.com/watch?v=tooshort',     // bad id length
            'https://www.youtube.com/watch',                 // no id
            'https://youtu.be/',                             // empty id
            'https://www.youtube.com/playlist?list=PL12345', // not a video link
        ] as $url) {
            $this->withHeaders($this->auth())->patchJson("/api/v1/admin/movies/{$movie->id}", [
                'sources' => [['label' => 'youtube', 'kind' => 'youtube', 'url' => $url, 'is_default' => true]],
            ])->assertStatus(422)->assertJsonValidationErrors(['sources.0.url']);
        }
    }

    public function test_youtube_sessions_return_the_canonical_url_and_video_id_without_cdn_signing(): void
    {
        config(['ott.playback.signed_urls' => true]);
        $this->app->instance(PlaybackUrlSigner::class, new class implements PlaybackUrlSigner
        {
            public function sign(VideoSource $source, PlaybackSession $session): SignedPlaybackUrl
            {
                throw new \LogicException('YouTube sources must never reach the signer.');
            }
        });

        $movie = Movie::factory()->create();
        VideoSource::factory()->for($movie)->create([
            'kind' => 'youtube', 'url' => 'https://www.youtube.com/watch?v=M7lc1UVf-VE', 'is_default' => true,
        ]);
        $token = User::factory()->subscriber()->create()->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/playback/sessions', ['movie_id' => $movie->id])
            ->assertCreated()
            ->assertJsonPath('data.source.kind', 'youtube')
            ->assertJsonPath('data.source.video_id', 'M7lc1UVf-VE')
            ->assertJsonPath('data.url', 'https://www.youtube.com/watch?v=M7lc1UVf-VE')
            ->assertJsonPath('data.signature_required', false)
            ->assertJsonPath('data.credentials_required', false)
            ->assertJsonPath('data.renew_after_seconds', null);
    }

    public function test_youtube_sessions_still_enforce_entitlements(): void
    {
        $movie = Movie::factory()->create();
        VideoSource::factory()->for($movie)->create(['kind' => 'youtube', 'url' => 'https://youtu.be/M7lc1UVf-VE', 'is_default' => true]);

        // No token at all.
        $this->postJson('/api/v1/playback/sessions', ['movie_id' => $movie->id])->assertUnauthorized();

        // Expired subscription.
        $expired = User::factory()->create(['subscription_expires_at' => now()->subDay()]);
        $this->withToken($expired->createToken('test')->plainTextToken)
            ->postJson('/api/v1/playback/sessions', ['movie_id' => $movie->id])
            ->assertForbidden();
    }

    public function test_dash_sessions_are_signed_like_other_transports(): void
    {
        config(['ott.playback.signed_urls' => true]);
        $this->app->instance(PlaybackUrlSigner::class, new class implements PlaybackUrlSigner
        {
            public function sign(VideoSource $source, PlaybackSession $session): SignedPlaybackUrl
            {
                return new SignedPlaybackUrl($source->url.'?sig=test', now()->addMinutes(5));
            }
        });

        $movie = Movie::factory()->create();
        VideoSource::factory()->for($movie)->create([
            'kind' => 'dash', 'url' => 'https://cdn.example.test/movie/manifest.mpd', 'is_default' => true,
        ]);
        $token = User::factory()->subscriber()->create()->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/playback/sessions', ['movie_id' => $movie->id])
            ->assertCreated()
            ->assertJsonPath('data.source.kind', 'dash')
            ->assertJsonPath('data.url', 'https://cdn.example.test/movie/manifest.mpd?sig=test')
            ->assertJsonPath('data.signature_required', true);
    }

    public function test_episode_sources_accept_youtube_and_dash_kinds(): void
    {
        $series = Series::factory()->create();
        $season = Season::factory()->for($series)->create();

        $this->withHeaders($this->auth())->postJson("/api/v1/admin/series/{$series->id}/seasons/{$season->id}/episodes", [
            'episode_number' => 1,
            'sources' => [
                ['label' => 'yt', 'kind' => 'youtube', 'url' => 'https://www.youtube.com/watch?v=M7lc1UVf-VE', 'is_default' => true],
                ['label' => 'dash', 'kind' => 'dash', 'url' => 'https://cdn.example.test/ep1/manifest.mpd'],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('video_sources', ['label' => 'yt', 'kind' => 'youtube']);
        $this->assertDatabaseHas('video_sources', ['label' => 'dash', 'kind' => 'dash']);
    }

    public function test_youtube_episode_contract_and_public_url_privacy(): void
    {
        $episode = SeriesEpisode::factory()->create();
        $source = VideoSource::factory()->forEpisode($episode)->create([
            'kind' => 'youtube', 'url' => 'https://youtu.be/M7lc1UVf-VE?si=tracking', 'is_default' => true,
        ]);
        $viewer = User::factory()->subscriber()->create();
        $this->withToken($viewer->createToken('test')->plainTextToken)
            ->postJson('/api/v1/playback/sessions', ['episode_id' => $episode->id])
            ->assertCreated()->assertJsonPath('data.source.video_id', 'M7lc1UVf-VE')
            ->assertJsonPath('data.url', 'https://www.youtube.com/watch?v=M7lc1UVf-VE')
            ->assertJsonPath('data.renew_after_seconds', null)->assertJsonPath('data.signature_required', false);
        $this->assertSame('https://www.youtube.com/watch?v=M7lc1UVf-VE', $source->fresh()->url);
        $public = $this->getJson('/api/v1/series/'.$episode->series->slug)->assertOk();
        $this->assertStringNotContainsString('youtube.com', $public->getContent());
        $this->assertStringNotContainsString('M7lc1UVf-VE', $public->getContent());
    }

    public function test_legacy_youtube_link_normalizes_in_session_without_rewriting_source(): void
    {
        $source = VideoSource::factory()->create(['kind' => 'youtube', 'url' => 'https://youtu.be/M7lc1UVf-VE', 'is_default' => true]);
        DB::table('video_sources')->where('id', $source->id)->update(['url' => 'https://youtu.be/M7lc1UVf-VE?t=20']);
        $token = User::factory()->subscriber()->create()->createToken('test')->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/playback/sessions', ['movie_id' => $source->movie_id])
            ->assertCreated()->assertJsonPath('data.url', 'https://www.youtube.com/watch?v=M7lc1UVf-VE')
            ->assertJsonPath('data.source.video_id', 'M7lc1UVf-VE');
        $this->assertSame('https://youtu.be/M7lc1UVf-VE?t=20', $source->fresh()->url);
    }

    public function test_malformed_legacy_youtube_source_cannot_be_played(): void
    {
        $source = VideoSource::factory()->create(['kind' => 'youtube', 'url' => 'https://notyoutube.com/watch?v=M7lc1UVf-VE']);
        $token = User::factory()->subscriber()->create()->createToken('test')->plainTextToken;
        $response = $this->withToken($token)->postJson('/api/v1/playback/sessions', ['movie_id' => $source->movie_id])->assertForbidden();
        $this->assertStringNotContainsString('notyoutube.com', $response->getContent());
    }

    public function test_youtube_session_renewal_requires_an_active_session(): void
    {
        $source = VideoSource::factory()->create(['kind' => 'youtube', 'url' => 'https://youtu.be/M7lc1UVf-VE', 'is_default' => true]);
        $token = User::factory()->subscriber()->create()->createToken('test')->plainTextToken;
        $session = $this->withToken($token)->postJson('/api/v1/playback/sessions', ['movie_id' => $source->movie_id])->assertCreated()->json('data.session_id');
        $this->travel(61)->minutes();
        $this->withToken($token)->postJson('/api/v1/playback/sessions/'.$session.'/renew')->assertForbidden();
    }

    public function test_youtube_does_not_bypass_protected_hls_or_source_ownership(): void
    {
        config(['ott.playback.signed_urls' => true, 'ott.external_cdn.verified' => false]);
        $movie = Movie::factory()->create();
        VideoSource::factory()->for($movie)->create(['label' => 'protected', 'kind' => 'hls', 'url' => 'https://cdn.goldendragon2000.com/private/master.m3u8', 'is_default' => true]);
        VideoSource::factory()->for($movie)->create(['label' => 'sample', 'kind' => 'youtube', 'url' => 'https://youtu.be/M7lc1UVf-VE', 'is_default' => false]);
        VideoSource::factory()->create(['label' => 'foreign', 'kind' => 'youtube', 'url' => 'https://youtu.be/M7lc1UVf-VE']);
        $token = User::factory()->subscriber()->create()->createToken('test')->plainTextToken;
        $protected = $this->withToken($token)->postJson('/api/v1/playback/sessions', ['movie_id' => $movie->id])->assertStatus(503);
        $this->assertStringNotContainsString('cdn.goldendragon2000.com', $protected->getContent());
        $this->withToken($token)->postJson('/api/v1/playback/sessions', ['movie_id' => $movie->id, 'source' => 'foreign'])->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/playback/sessions', ['movie_id' => $movie->id, 'source' => 'sample'])->assertCreated()->assertJsonPath('data.source.kind', 'youtube');
        $public = $this->getJson('/api/v1/movies/'.$movie->slug)->assertOk();
        $this->assertStringNotContainsString('cdn.goldendragon2000.com', $public->getContent());
        $this->assertStringNotContainsString('M7lc1UVf-VE', $public->getContent());
    }

    public function test_hls_and_dash_reject_insecure_or_wrong_manifest_paths(): void
    {
        $movie = Movie::factory()->create();
        foreach ([['hls', 'http://cdn.example.test/master.m3u8'], ['hls', 'https://cdn.example.test/video.mp4'], ['dash', 'http://cdn.example.test/manifest.mpd'], ['dash', 'https://cdn.example.test/video.mp4']] as [$kind, $url]) {
            $this->withHeaders($this->auth())->patchJson('/api/v1/admin/movies/'.$movie->id, [
                'sources' => [['label' => 'main', 'kind' => $kind, 'url' => $url]],
            ])->assertUnprocessable()->assertJsonValidationErrors('sources.0.url');
        }
    }
}
