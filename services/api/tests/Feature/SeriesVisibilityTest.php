<?php

namespace Tests\Feature;

use App\Models\Season;
use App\Models\Series;
use App\Models\SeriesEpisode;
use App\Models\User;
use App\Models\VideoSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ApiHelpers;
use Tests\TestCase;

/**
 * Publication visibility across the whole hierarchy: an unpublished series OR
 * season must not expose playable episodes through detail, season/episode
 * listings, playback sessions, renewal, or progress writes.
 */
class SeriesVisibilityTest extends TestCase
{
    use ApiHelpers;
    use RefreshDatabase;

    private function tree(bool $seriesPublished = true, bool $seasonPublished = true, bool $episodePublished = true): SeriesEpisode
    {
        $series = Series::factory()->create(['published' => $seriesPublished, 'published_at' => $seriesPublished ? now() : null]);
        $season = Season::factory()->for($series)->numbered(1)->create(['published' => $seasonPublished]);
        $episode = SeriesEpisode::factory()->for($series)->create([
            'season_id' => $season->id, 'episode_number' => 1, 'published' => $episodePublished,
            'title' => 'Confidential episode title',
        ]);
        VideoSource::factory()->forEpisode($episode)->create();

        return $episode->load(['season', 'series', 'videoSources']);
    }

    private function viewerToken(): string
    {
        return User::factory()->create(['subscription_expires_at' => now()->addMonth()])
            ->createToken('web')->plainTextToken;
    }

    public function test_unpublished_season_hides_episodes_from_public_detail(): void
    {
        $episode = $this->tree(seasonPublished: false);

        $response = $this->getJson("/api/v1/series/{$episode->series->slug}")->assertOk();
        $response->assertJsonCount(0, 'data.seasons')->assertJsonCount(0, 'data.episodes');
        $this->assertStringNotContainsString('Confidential episode title', $response->getContent());
    }

    public function test_unpublished_series_404s_detail_seasons_and_episodes(): void
    {
        $episode = $this->tree(seriesPublished: false);
        $slug = $episode->series->slug;

        $this->getJson("/api/v1/series/{$slug}")->assertStatus(404);
        $this->getJson("/api/v1/series/{$slug}/seasons")->assertStatus(404);
        $this->getJson("/api/v1/series/{$slug}/seasons/1/episodes")->assertStatus(404);
    }

    public function test_unpublished_season_404s_its_public_episode_listing(): void
    {
        $episode = $this->tree(seasonPublished: false);

        $this->getJson("/api/v1/series/{$episode->series->slug}/seasons")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/series/{$episode->series->slug}/seasons/1/episodes")->assertStatus(404);
    }

    public function test_season_number_from_another_series_404s(): void
    {
        $episode = $this->tree();
        $other = Series::factory()->create(['slug' => 'other-show']);

        $this->getJson("/api/v1/series/{$episode->series->slug}/seasons/1/episodes")->assertOk();
        $this->getJson("/api/v1/series/{$other->slug}/seasons/1/episodes")->assertStatus(404);
    }

    public function test_admin_sees_draft_hierarchy_as_preview(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $admin = User::factory()->administrator()->create();
        $episode = $this->tree(seasonPublished: false, episodePublished: false);

        $response = $this->withHeaders($this->bearer($this->adminToken($admin)))
            ->getJson("/api/v1/series/{$episode->series->slug}")->assertOk();
        $response->assertJsonCount(1, 'data.seasons')->assertJsonCount(1, 'data.episodes');
    }

    public function test_playback_and_renewal_are_refused_for_unpublished_season(): void
    {
        $token = $this->viewerToken();
        $episode = $this->tree(seasonPublished: false);

        $response = $this->withHeaders($this->bearer($token))
            ->postJson('/api/v1/playback/sessions', ['episode_id' => $episode->id])
            ->assertStatus(403);
        $this->assertStringNotContainsString('m3u8', $response->getContent());
        $this->assertDatabaseCount('playback_sessions', 0);
    }

    public function test_renewal_fails_after_the_season_is_unpublished_mid_session(): void
    {
        $token = $this->viewerToken();
        $episode = $this->tree();

        $session = $this->withHeaders($this->bearer($token))
            ->postJson('/api/v1/playback/sessions', ['episode_id' => $episode->id])
            ->assertCreated()
            ->json('data.session_id');

        $episode->season->update(['published' => false]);

        $this->withHeaders($this->bearer($token))
            ->postJson("/api/v1/playback/sessions/{$session}/renew")
            ->assertStatus(403);
    }

    public function test_progress_writes_are_rejected_for_unpublished_hierarchy(): void
    {
        $token = $this->viewerToken();

        foreach ([
            ['seriesPublished' => false],
            ['seasonPublished' => false],
            ['episodePublished' => false],
        ] as $flags) {
            $episode = $this->tree(...$flags);

            $this->withHeaders($this->bearer($token))
                ->postJson('/api/v1/watch-progress', [
                    'episode_id' => $episode->id, 'position_seconds' => 60,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['episode_id']);
        }

        $this->assertDatabaseCount('watch_progress', 0);
    }

    public function test_series_index_counts_only_visible_episodes(): void
    {
        $series = Series::factory()->create();
        $publishedSeason = Season::factory()->for($series)->numbered(1)->create();
        $draftSeason = Season::factory()->for($series)->numbered(2)->draft()->create();
        SeriesEpisode::factory()->for($series)->create(['season_id' => $publishedSeason->id, 'episode_number' => 1]);
        SeriesEpisode::factory()->for($series)->create(['season_id' => $publishedSeason->id, 'episode_number' => 2, 'published' => false]);
        SeriesEpisode::factory()->for($series)->create(['season_id' => $draftSeason->id, 'episode_number' => 1]);

        $this->getJson('/api/v1/series')
            ->assertOk()
            ->assertJsonPath('data.0.episode_count', 1)
            ->assertJsonPath('data.0.season_count', 1);
    }
}
