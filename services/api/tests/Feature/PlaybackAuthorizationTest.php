<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\PlaybackSession;
use App\Models\User;
use App\Models\VideoSource;
use App\Support\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ApiHelpers;
use Tests\TestCase;

class PlaybackAuthorizationTest extends TestCase
{
    use ApiHelpers;
    use RefreshDatabase;

    private function movieWithSource(array $movieAttributes = [], array $sourceAttributes = []): Movie
    {
        $movie = Movie::factory()->create($movieAttributes);
        VideoSource::factory()->for($movie)->create($sourceAttributes);

        return $movie->load('videoSources');
    }

    private function play(User|string $actor, Movie $movie, array $extra = []): TestResponse
    {
        $token = is_string($actor) ? $actor : $actor->createToken('web')->plainTextToken;

        return $this->withHeaders($this->bearer($token))
            ->postJson('/api/v1/playback/sessions', ['movie_id' => $movie->id] + $extra);
    }

    public function test_entitled_viewer_receives_a_short_lived_session(): void
    {
        $movie = $this->movieWithSource();
        $viewer = User::factory()->create(['subscription_expires_at' => now()->addMonth()]);

        $response = $this->play($viewer, $movie)->assertCreated();

        $response->assertJsonPath('data.url', $movie->videoSources->first()->url)
            ->assertJsonPath('data.source.label', 'main')
            ->assertJsonPath('data.signature_required', false)
            ->assertJsonStructure(['data' => ['session_id', 'expires_at', 'movie' => ['id', 'slug', 'title']]]);

        $session = PlaybackSession::query()->firstOrFail();

        $this->assertSame($viewer->id, $session->user_id);
        $this->assertSame($movie->id, $session->movie_id);
        $this->assertNotEmpty($session->public_id);
        $this->assertTrue($session->expires_at->isFuture());
        $this->assertTrue(
            $session->expires_at->diffInSeconds(now()->addSeconds((int) config('ott.playback.ttl_seconds')), true) < 5,
        );

        // The stored session must not serialise viewer identity details back out.
        $this->assertStringNotContainsString('203.0.113', $response->getContent());
    }

    public function test_playback_requires_authentication(): void
    {
        $movie = $this->movieWithSource();

        $this->postJson('/api/v1/playback/sessions', ['movie_id' => $movie->id])
            ->assertStatus(401)
            ->assertJsonPath('code', 'unauthenticated');

        $this->assertSame(0, PlaybackSession::query()->count());
    }

    public function test_unpublished_movie_is_refused_even_with_a_source(): void
    {
        $movie = $this->movieWithSource(['published' => false]);
        $viewer = User::factory()->create();

        $this->play($viewer, $movie)
            ->assertStatus(403)
            ->assertJsonPath('code', Entitlements::DENY_MOVIE_UNPUBLISHED);

        $this->assertSame(0, PlaybackSession::query()->count());
    }

    public function test_expired_subscription_is_refused(): void
    {
        $movie = $this->movieWithSource();
        $viewer = User::factory()->expiredSubscription()->create();

        $this->play($viewer, $movie)
            ->assertStatus(403)
            ->assertJsonPath('code', Entitlements::DENY_SUBSCRIPTION_EXPIRED);

        $this->assertSame(0, PlaybackSession::query()->count());
    }

    public function test_missing_subscription_record_is_entitled_until_billing_lands(): void
    {
        $movie = $this->movieWithSource();
        $viewer = User::factory()->lifetimeAccess()->create();

        $this->assertTrue(config('ott.playback.allow_missing_subscription'));
        $this->play($viewer, $movie)->assertCreated();
    }

    public function test_administrators_are_entitled_for_quality_assurance(): void
    {
        $movie = $this->movieWithSource();
        $admin = User::factory()->administrator()->expiredSubscription()->create();

        $this->play($admin, $movie)->assertCreated();
    }

    public function test_movie_without_an_enabled_source_is_refused(): void
    {
        $movie = Movie::factory()->create();
        $viewer = User::factory()->create();

        $this->play($viewer, $movie)->assertStatus(403)->assertJsonPath('code', Entitlements::DENY_NO_SOURCE);

        $disabled = Movie::factory()->create();
        VideoSource::factory()->for($disabled)->create(['enabled' => false, 'is_default' => false]);

        $this->play($viewer, $disabled)->assertStatus(403)->assertJsonPath('code', Entitlements::DENY_NO_SOURCE);
    }

    public function test_a_viewer_can_choose_an_enabled_source_by_label(): void
    {
        $movie = Movie::factory()->create();
        VideoSource::factory()->for($movie)->create(['label' => 'hd', 'is_default' => true, 'url' => 'https://cdn.example.test/vod/hd.m3u8']);
        VideoSource::factory()->for($movie)->create(['label' => 'sd', 'is_default' => false, 'url' => 'https://cdn.example.test/vod/sd.m3u8']);
        VideoSource::factory()->for($movie)->create(['label' => 'broken', 'enabled' => false, 'is_default' => false, 'url' => 'https://cdn.example.test/vod/broken.m3u8']);

        $viewer = User::factory()->create();

        $this->play($viewer, $movie, ['source' => 'sd'])
            ->assertCreated()
            ->assertJsonPath('data.url', 'https://cdn.example.test/vod/sd.m3u8')
            ->assertJsonPath('data.source.label', 'sd');

        $this->play($viewer, $movie, ['source' => 'broken'])
            ->assertStatus(403)
            ->assertJsonPath('code', Entitlements::DENY_NO_SOURCE);

        $this->play($viewer, $movie, ['source' => 'nope'])
            ->assertStatus(403)
            ->assertJsonPath('code', Entitlements::DENY_NO_SOURCE);

        // With no label the default source is used.
        $this->play($viewer, $movie)
            ->assertCreated()
            ->assertJsonPath('data.source.label', 'hd');
    }

    public function test_playback_input_is_validated(): void
    {
        $viewer = User::factory()->create();
        $token = $viewer->createToken('web')->plainTextToken;

        $this->withHeaders($this->bearer($token))->postJson('/api/v1/playback/sessions', [])
            ->assertStatus(422)->assertJsonValidationErrors(['movie_id']);

        $this->withHeaders($this->bearer($token))->postJson('/api/v1/playback/sessions', ['movie_id' => 999999])
            ->assertStatus(422)->assertJsonValidationErrors(['movie_id']);

        $this->withHeaders($this->bearer($token))->postJson('/api/v1/playback/sessions', ['movie_id' => 'not-an-int'])
            ->assertStatus(422);
    }

    public function test_playback_sessions_feed_the_trending_row(): void
    {
        $popular = $this->movieWithSource();
        $quiet = $this->movieWithSource();
        $viewer = User::factory()->create();

        $this->play($viewer, $popular)->assertCreated();
        $this->play($viewer, $popular)->assertCreated();
        $this->play($viewer, $quiet)->assertCreated();

        $trending = collect($this->getJson('/api/v1/home')->assertOk()->json('data.trending'))->pluck('id');

        $this->assertSame($popular->id, $trending->first());
    }

    public function test_public_catalog_endpoints_never_hand_out_the_stream_url(): void
    {
        $movie = $this->movieWithSource();

        foreach (['/api/v1/movies', '/api/v1/movies/'.$movie->slug, '/api/v1/home'] as $url) {
            $response = $this->getJson($url)->assertOk();

            $this->assertStringNotContainsString('m3u8', $response->getContent(), $url.' leaked the origin URL');
        }
    }
}
