<?php

namespace Tests\Feature;

use App\Models\Season;
use App\Models\Series;
use App\Models\SeriesEpisode;
use App\Models\User;
use App\Models\WatchProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ApiHelpers;
use Tests\TestCase;

/**
 * Episode progress, resume and completion: independent per episode, owner
 * scoped, hierarchy visibility aware, and cleanly discriminated from movie
 * progress in listings and Continue Watching.
 */
class EpisodeProgressTest extends TestCase
{
    use ApiHelpers;
    use RefreshDatabase;

    private User $viewer;

    /** @var array<string, string> */
    private array $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->viewer = User::factory()->create(['subscription_expires_at' => now()->addMonth()]);
        $this->auth = $this->bearer($this->viewer->createToken('web')->plainTextToken);
    }

    public function test_episode_progress_is_saved_resumed_and_completed(): void
    {
        $episode = SeriesEpisode::factory()->create(['episode_number' => 1]);

        $this->withHeaders($this->auth)->postJson('/api/v1/watch-progress', [
            'episode_id' => $episode->id, 'position_seconds' => 300, 'duration_seconds' => 1200,
        ])->assertCreated()
            ->assertJsonPath('data.type', 'episode')
            ->assertJsonPath('data.episode.id', $episode->id)
            ->assertJsonPath('data.episode.season_number', 1)
            ->assertJsonPath('data.episode.series.slug', $episode->series->slug)
            ->assertJsonPath('data.position_seconds', 300)
            ->assertJsonPath('data.completed', false);

        // Resume: the same owner+episode upserts instead of duplicating.
        $this->withHeaders($this->auth)->postJson('/api/v1/watch-progress', [
            'episode_id' => $episode->id, 'position_seconds' => 900, 'duration_seconds' => 1200,
        ])->assertOk()->assertJsonPath('data.position_seconds', 900);
        $this->assertDatabaseCount('watch_progress', 1);

        // Completion.
        $this->withHeaders($this->auth)->postJson('/api/v1/watch-progress', [
            'episode_id' => $episode->id, 'position_seconds' => 1200, 'duration_seconds' => 1200, 'completed' => true,
        ])->assertOk()->assertJsonPath('data.completed', true);
    }

    public function test_progress_is_independent_across_episodes_and_owners(): void
    {
        $series = Series::factory()->create();
        $season = Season::factory()->for($series)->create();
        $ep1 = SeriesEpisode::factory()->for($series)->create(['season_id' => $season->id, 'episode_number' => 1]);
        $ep2 = SeriesEpisode::factory()->for($series)->create(['season_id' => $season->id, 'episode_number' => 2]);

        $this->withHeaders($this->auth)->postJson('/api/v1/watch-progress', ['episode_id' => $ep1->id, 'position_seconds' => 100])->assertCreated();
        $this->withHeaders($this->auth)->postJson('/api/v1/watch-progress', ['episode_id' => $ep2->id, 'position_seconds' => 20])->assertCreated();

        // Another viewer's row on the same episode: Sanctum's guard caches the
        // resolved user per test method, so the second owner is set up at the
        // data layer instead of a second HTTP identity in the same test.
        $other = User::factory()->create();
        WatchProgress::factory()->for($other, 'user')->forEpisode($ep1)->create(['position_seconds' => 555]);

        $this->assertDatabaseCount('watch_progress', 3);
        $this->assertDatabaseHas('watch_progress', ['user_id' => $this->viewer->id, 'episode_id' => $ep1->id, 'position_seconds' => 100]);
        $this->assertDatabaseHas('watch_progress', ['user_id' => $other->id, 'episode_id' => $ep1->id, 'position_seconds' => 555]);

        // The API only ever exposes the caller's own rows.
        $this->withHeaders($this->auth)->getJson('/api/v1/watch-progress')
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_movie_and_episode_ids_cannot_be_combined_or_omitted(): void
    {
        $episode = SeriesEpisode::factory()->create();

        $this->withHeaders($this->auth)->postJson('/api/v1/watch-progress', ['position_seconds' => 10])
            ->assertStatus(422);
        $this->withHeaders($this->auth)->postJson('/api/v1/watch-progress', [
            'episode_id' => $episode->id, 'movie_id' => 4242, 'position_seconds' => 10,
        ])->assertStatus(422)->assertJsonValidationErrors(['episode_id']);
    }

    public function test_progress_listing_mixes_typed_rows_and_filters_by_episode(): void
    {
        $episode = SeriesEpisode::factory()->create();
        $movie = \App\Models\Movie::factory()->create(['published' => true]);

        $this->withHeaders($this->auth)->postJson('/api/v1/watch-progress', ['episode_id' => $episode->id, 'position_seconds' => 10])->assertCreated();
        $this->withHeaders($this->auth)->postJson('/api/v1/watch-progress', ['movie_id' => $movie->id, 'position_seconds' => 20])->assertCreated();

        $all = $this->withHeaders($this->auth)->getJson('/api/v1/watch-progress')->assertOk();
        $types = collect($all->json('data'))->pluck('type')->sort()->values()->all();
        $this->assertSame(['episode', 'movie'], $types);

        // Movie rows keep their Phase 1 shape exactly.
        $movieRow = collect($all->json('data'))->firstWhere('type', 'movie');
        $this->assertArrayNotHasKey('episode', $movieRow);
        $this->assertSame($movie->id, $movieRow['movie']['id']);

        $this->withHeaders($this->auth)->getJson("/api/v1/watch-progress?episode_id={$episode->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'episode');

        $this->withHeaders($this->auth)->getJson("/api/v1/watch-progress?movie_id={$movie->id}&episode_id={$episode->id}")
            ->assertStatus(422);
    }

    public function test_guests_cannot_write_or_read_progress(): void
    {
        $episode = SeriesEpisode::factory()->create();

        $this->postJson('/api/v1/watch-progress', ['episode_id' => $episode->id, 'position_seconds' => 10])
            ->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->getJson('/api/v1/watch-progress')->assertStatus(401);
    }

    public function test_continue_watching_discriminates_movies_from_episodes(): void
    {
        $episode = SeriesEpisode::factory()->create(['episode_number' => 4]);
        $movie = \App\Models\Movie::factory()->create(['published' => true]);

        $this->withHeaders($this->auth)->postJson('/api/v1/watch-progress', ['episode_id' => $episode->id, 'position_seconds' => 60, 'duration_seconds' => 600])->assertCreated();
        $this->withHeaders($this->auth)->postJson('/api/v1/watch-progress', ['movie_id' => $movie->id, 'position_seconds' => 120, 'duration_seconds' => 1200])->assertCreated();

        $home = $this->withHeaders($this->auth)->getJson('/api/v1/home')->assertOk();
        $rows = collect($home->json('data.continue_watching'));

        $episodeRow = $rows->firstWhere('type', 'episode');
        $this->assertNotNull($episodeRow);
        $this->assertSame($episode->id, $episodeRow['episode']['id']);
        $this->assertSame(4, $episodeRow['episode']['episode_number']);
        $this->assertSame($episode->series->slug, $episodeRow['series']['slug']);
        $this->assertSame(60, $episodeRow['position_seconds']);

        $movieRow = $rows->firstWhere('type', 'movie');
        $this->assertNotNull($movieRow);
        $this->assertSame($movie->id, $movieRow['id']);

        // Anonymous visitors get an empty personalized rail. The guard caches
        // the resolved user per test method, so it must be forgotten as well.
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/home')->assertOk()->assertJsonPath('data.continue_watching', []);
    }

    public function test_completed_and_unpublished_hierarchy_rows_disappear_from_continue_watching(): void
    {
        $completed = SeriesEpisode::factory()->create();
        $draftSeasonEpisode = SeriesEpisode::factory()->create();
        $active = SeriesEpisode::factory()->create();

        $this->withHeaders($this->auth)->postJson('/api/v1/watch-progress', [
            'episode_id' => $completed->id, 'position_seconds' => 600, 'completed' => true,
        ])->assertCreated();
        $this->withHeaders($this->auth)->postJson('/api/v1/watch-progress', [
            'episode_id' => $draftSeasonEpisode->id, 'position_seconds' => 30,
        ])->assertCreated();
        $this->withHeaders($this->auth)->postJson('/api/v1/watch-progress', [
            'episode_id' => $active->id, 'position_seconds' => 45,
        ])->assertCreated();

        $draftSeasonEpisode->season->update(['published' => false]);

        $home = $this->withHeaders($this->auth)->getJson('/api/v1/home')->assertOk();
        $ids = collect($home->json('data.continue_watching'))->pluck('id')->all();
        $this->assertSame([$active->id], $ids);

        // The unpublished row also disappears from the progress listing...
        $list = $this->withHeaders($this->auth)->getJson('/api/v1/watch-progress')->assertOk();
        $listIds = collect($list->json('data'))->pluck('episode.id')->filter()->all();
        $this->assertNotContains($draftSeasonEpisode->id, $listIds);
        // ...but the stored row is retained (never silently deleted).
        $this->assertDatabaseHas('watch_progress', ['episode_id' => $draftSeasonEpisode->id]);
    }

    public function test_position_cannot_exceed_duration_for_episodes(): void
    {
        $episode = SeriesEpisode::factory()->create();

        $this->withHeaders($this->auth)->postJson('/api/v1/watch-progress', [
            'episode_id' => $episode->id, 'position_seconds' => 90, 'duration_seconds' => 60,
        ])->assertStatus(422)->assertJsonValidationErrors(['position_seconds']);
    }
}
