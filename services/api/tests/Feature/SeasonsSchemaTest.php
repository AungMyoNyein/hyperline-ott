<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\Season;
use App\Models\Series;
use App\Models\SeriesEpisode;
use App\Models\User;
use App\Models\VideoSource;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 2 database ownership and numbering constraints: the invariants must be
 * enforced by the database itself, not only by request validation.
 */
class SeasonsSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_season_number_is_unique_within_a_series_but_reusable_across_series(): void
    {
        $a = Series::factory()->create();
        $b = Series::factory()->create();

        Season::factory()->for($a)->numbered(1)->create();
        Season::factory()->for($b)->numbered(1)->create();

        $this->expectException(QueryException::class);
        Season::factory()->for($a)->numbered(1)->create();
    }

    public function test_episode_number_is_unique_within_a_season_but_reusable_across_seasons(): void
    {
        $series = Series::factory()->create();
        $s1 = Season::factory()->for($series)->numbered(1)->create();
        $s2 = Season::factory()->for($series)->numbered(2)->create();

        SeriesEpisode::factory()->for($series)->create(['season_id' => $s1->id, 'episode_number' => 1]);
        SeriesEpisode::factory()->for($series)->create(['season_id' => $s2->id, 'episode_number' => 1]);

        $this->expectException(QueryException::class);
        SeriesEpisode::factory()->for($series)->create(['season_id' => $s1->id, 'episode_number' => 1]);
    }

    public function test_composite_foreign_key_rejects_an_episode_pointing_at_another_series_season(): void
    {
        $a = Series::factory()->create();
        $b = Series::factory()->create();
        $seasonOfB = Season::factory()->for($b)->numbered(1)->create();

        $this->expectException(QueryException::class);
        DB::table('series_episodes')->insert([
            'series_id' => $a->id,
            'season_id' => $seasonOfB->id,
            'episode_number' => 1,
            'published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_deleting_a_season_cascades_to_its_episodes_and_their_sources(): void
    {
        $series = Series::factory()->create();
        $season = Season::factory()->for($series)->create();
        $episode = SeriesEpisode::factory()->for($series)->create(['season_id' => $season->id, 'episode_number' => 1]);
        VideoSource::factory()->forEpisode($episode)->create();

        $season->delete();

        $this->assertDatabaseCount('series_episodes', 0);
        $this->assertDatabaseCount('video_sources', 0);
    }

    public function test_deleting_a_series_cascades_to_seasons_and_episodes(): void
    {
        $series = Series::factory()->create();
        $season = Season::factory()->for($series)->create();
        SeriesEpisode::factory()->for($series)->create(['season_id' => $season->id, 'episode_number' => 1]);

        $series->delete();

        $this->assertDatabaseCount('seasons', 0);
        $this->assertDatabaseCount('series_episodes', 0);
    }

    public function test_watch_progress_requires_exactly_one_owner_at_the_database_level(): void
    {
        $user = User::factory()->create();

        try {
            DB::table('watch_progress')->insert([
                'user_id' => $user->id, 'movie_id' => null, 'episode_id' => null,
                'position_seconds' => 10, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->fail('Ownerless watch_progress row was accepted.');
        } catch (QueryException $expected) {
            $this->addToAssertionCount(1);
        }

        $movie = Movie::factory()->create(['published' => true]);
        $episode = SeriesEpisode::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('watch_progress')->insert([
            'user_id' => $user->id, 'movie_id' => $movie->id, 'episode_id' => $episode->id,
            'position_seconds' => 10, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_watch_progress_keeps_one_row_per_user_and_episode(): void
    {
        $user = User::factory()->create();
        $episode = SeriesEpisode::factory()->create();

        DB::table('watch_progress')->insert([
            'user_id' => $user->id, 'episode_id' => $episode->id,
            'position_seconds' => 10, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('watch_progress')->insert([
            'user_id' => $user->id, 'episode_id' => $episode->id,
            'position_seconds' => 50, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_video_sources_require_exactly_one_owner_at_the_database_level(): void
    {
        $this->expectException(QueryException::class);
        DB::table('video_sources')->insert([
            'movie_id' => null, 'episode_id' => null,
            'label' => 'main', 'kind' => 'hls', 'url' => 'https://cdn.example.test/a.m3u8',
            'is_default' => true, 'enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_playback_sessions_require_exactly_one_owner_at_the_database_level(): void
    {
        $user = User::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('playback_sessions')->insert([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $user->id, 'movie_id' => null, 'episode_id' => null,
            'status' => 'active', 'started_at' => now(), 'expires_at' => now()->addMinutes(15),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
