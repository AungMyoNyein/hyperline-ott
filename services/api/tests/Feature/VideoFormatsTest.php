<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\User;
use App\Models\VideoSource;
use App\Support\Contracts\PlaybackUrlSigner;
use App\Support\Playback\SignedPlaybackUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->administrator()->create();
        // The SSRF guard resolves hosts through the stub; give YouTube public IPs.
        foreach (['youtube.com', 'www.youtube.com', 'youtu.be', 'www.youtu.be'] as $host) {
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
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://youtu.be/dQw4w9WgXcQ',
            'https://www.youtube.com/embed/dQw4w9WgXcQ',
            'https://www.youtube.com/shorts/dQw4w9WgXcQ',
        ] as $index => $url) {
            $movie = Movie::factory()->create(['slug' => "yt-movie-{$index}"]);
            $this->withHeaders($this->auth())->patchJson("/api/v1/admin/movies/{$movie->id}", [
                'sources' => [['label' => 'youtube', 'kind' => 'youtube', 'url' => $url, 'is_default' => true, 'enabled' => true]],
            ])->assertOk();
            $this->assertDatabaseHas('video_sources', ['movie_id' => $movie->id, 'kind' => 'youtube', 'url' => $url]);
        }
    }

    public function test_invalid_youtube_links_are_rejected(): void
    {
        $movie = Movie::factory()->create();

        foreach ([
            'https://example.com/watch?v=dQw4w9WgXcQ',      // wrong host
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

    public function test_youtube_sessions_return_the_raw_embed_url_unsigned_even_with_signing_enabled(): void
    {
        config(['ott.playback.signed_urls' => true]);
        $this->app->instance(PlaybackUrlSigner::class, new class implements PlaybackUrlSigner
        {
            public function sign(VideoSource $source, \App\Models\PlaybackSession $session): SignedPlaybackUrl
            {
                throw new \LogicException('YouTube sources must never reach the signer.');
            }
        });

        $movie = Movie::factory()->create();
        VideoSource::factory()->for($movie)->create([
            'kind' => 'youtube', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'is_default' => true,
        ]);
        $token = User::factory()->subscriber()->create()->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/playback/sessions', ['movie_id' => $movie->id])
            ->assertCreated()
            ->assertJsonPath('data.source.kind', 'youtube')
            ->assertJsonPath('data.url', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ')
            ->assertJsonPath('data.signature_required', false)
            ->assertJsonPath('data.credentials_required', false)
            ->assertJsonPath('data.renew_after_seconds', null);
    }

    public function test_youtube_sessions_still_enforce_entitlements(): void
    {
        $movie = Movie::factory()->create();
        VideoSource::factory()->for($movie)->create(['kind' => 'youtube', 'url' => 'https://youtu.be/dQw4w9WgXcQ', 'is_default' => true]);

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
            public function sign(VideoSource $source, \App\Models\PlaybackSession $session): SignedPlaybackUrl
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
        $series = \App\Models\Series::factory()->create();
        $season = \App\Models\Season::factory()->for($series)->create();

        $this->withHeaders($this->auth())->postJson("/api/v1/admin/series/{$series->id}/seasons/{$season->id}/episodes", [
            'episode_number' => 1,
            'sources' => [
                ['label' => 'yt', 'kind' => 'youtube', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'is_default' => true],
                ['label' => 'dash', 'kind' => 'dash', 'url' => 'https://cdn.example.test/ep1/manifest.mpd'],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('video_sources', ['label' => 'yt', 'kind' => 'youtube']);
        $this->assertDatabaseHas('video_sources', ['label' => 'dash', 'kind' => 'dash']);
    }
}
