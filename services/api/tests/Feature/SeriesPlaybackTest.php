<?php

namespace Tests\Feature;

use App\Models\PlaybackSession;
use App\Models\Series;
use App\Models\SeriesEpisode;
use App\Models\User;
use App\Models\VideoSource;
use App\Support\Contracts\PlaybackUrlSigner;
use App\Support\Playback\SignedPlaybackUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ApiHelpers;
use Tests\TestCase;

class SeriesPlaybackTest extends TestCase
{
    use ApiHelpers;
    use RefreshDatabase;

    private function episode(bool $seriesPublished = true, bool $episodePublished = true): SeriesEpisode
    {
        $series = Series::factory()->create(['published' => $seriesPublished]);
        $episode = SeriesEpisode::factory()->for($series)->create(['episode_number' => 1, 'published' => $episodePublished]);
        VideoSource::factory()->forEpisode($episode)->create();

        return $episode->load(['series', 'videoSources']);
    }

    private function play(User|string $actor, SeriesEpisode $episode): \Illuminate\Testing\TestResponse
    {
        $token = is_string($actor) ? $actor : $actor->createToken('web')->plainTextToken;

        return $this->withHeaders($this->bearer($token))
            ->postJson('/api/v1/playback/sessions', ['episode_id' => $episode->id]);
    }

    public function test_guests_cannot_start_episode_playback(): void
    {
        $episode = $this->episode();

        $this->postJson('/api/v1/playback/sessions', ['episode_id' => $episode->id])
            ->assertStatus(401)
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_entitled_viewer_receives_a_session_for_a_published_episode(): void
    {
        $episode = $this->episode();
        $viewer = User::factory()->create(['subscription_expires_at' => now()->addMonth()]);

        $response = $this->play($viewer, $episode)->assertCreated();

        $response->assertJsonPath('data.episode.id', $episode->id);
        $response->assertJsonPath('data.episode.episode_number', 1);
        $response->assertJsonPath('data.movie', null);
        $response->assertJsonPath('data.signature_required', false);

        $session = PlaybackSession::firstOrFail();
        $this->assertSame($episode->id, (int) $session->episode_id);
        $this->assertNull($session->movie_id);
    }

    public function test_unpublished_episode_or_series_is_refused_with_403_and_no_url(): void
    {
        $viewer = User::factory()->create(['subscription_expires_at' => now()->addMonth()]);

        $draftEpisode = $this->episode(episodePublished: false);
        $response = $this->play($viewer, $draftEpisode)->assertStatus(403);
        $this->assertStringNotContainsString('m3u8', $response->getContent());

        $draftSeriesEpisode = $this->episode(seriesPublished: false);
        $this->play($viewer, $draftSeriesEpisode)->assertStatus(403);

        $this->assertDatabaseCount('playback_sessions', 0);
    }

    public function test_administrator_can_play_draft_episodes_for_qa(): void
    {
        $admin = User::factory()->administrator()->create();
        $episode = $this->episode(episodePublished: false);

        $this->play($admin, $episode)->assertCreated();
    }

    public function test_episode_playback_fails_closed_without_a_signer_and_leaks_nothing(): void
    {
        config(['ott.playback.signed_urls' => true, 'ott.playback.signer' => null]);
        $viewer = User::factory()->create(['subscription_expires_at' => now()->addMonth()]);
        $episode = $this->episode();

        $response = $this->play($viewer, $episode)->assertStatus(503);

        $this->assertStringNotContainsString('m3u8', $response->getContent());
        $this->assertStringNotContainsString('cdn.example.test', $response->getContent());
        $this->assertDatabaseCount('playback_sessions', 0);
    }

    public function test_mock_signer_issues_session_scoped_episode_url(): void
    {
        config(['ott.playback.signed_urls' => true]);
        $this->app->instance(PlaybackUrlSigner::class, new class implements PlaybackUrlSigner
        {
            public function sign(VideoSource $source, PlaybackSession $session): SignedPlaybackUrl
            {
                return new SignedPlaybackUrl('https://signed.example.test/'.$session->public_id.'/master.m3u8', $session->expires_at->copy()->subSeconds(10));
            }
        });

        $viewer = User::factory()->create(['subscription_expires_at' => now()->addMonth()]);
        $episode = $this->episode();

        $response = $this->play($viewer, $episode)->assertCreated();

        $session = PlaybackSession::firstOrFail();
        $response->assertJsonPath('data.signature_required', true);
        $this->assertStringContainsString($session->public_id, $response->json('data.url'));
        $this->assertStringNotContainsString($episode->videoSources->first()->url, $response->getContent());
    }

    public function test_movie_and_episode_ids_cannot_be_combined_or_omitted(): void
    {
        $viewer = User::factory()->create();
        $episode = $this->episode();

        $this->withHeaders($this->bearer($viewer->createToken('web')->plainTextToken))
            ->postJson('/api/v1/playback/sessions', [])
            ->assertStatus(422);

        $this->withHeaders($this->bearer($viewer->createToken('web')->plainTextToken))
            ->postJson('/api/v1/playback/sessions', ['episode_id' => $episode->id, 'movie_id' => 999999])
            ->assertStatus(422);
    }

    public function test_expired_subscription_cannot_play_episodes(): void
    {
        $viewer = User::factory()->expiredSubscription()->create();
        $episode = $this->episode();

        $this->play($viewer, $episode)->assertStatus(403);
        $this->assertDatabaseCount('playback_sessions', 0);
    }
}
