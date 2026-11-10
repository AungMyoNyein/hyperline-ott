<?php

namespace Tests\Feature;

use App\Models\Season;
use App\Models\Series;
use App\Models\SeriesEpisode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The documented next-episode ordering rule: published episodes of published
 * seasons, main seasons ascending, specials (season 0) last, episode numbers
 * ascending within each season. The public flat `episodes` list is the
 * authoritative order the clients walk for next/previous navigation.
 */
class EpisodeOrderingTest extends TestCase
{
    use RefreshDatabase;

    private function buildTree(): Series
    {
        $series = Series::factory()->create();

        $specials = Season::factory()->for($series)->specials()->create();
        $s2 = Season::factory()->for($series)->numbered(2)->create();
        $s1 = Season::factory()->for($series)->numbered(1)->create();

        SeriesEpisode::factory()->for($series)->create(['season_id' => $s1->id, 'episode_number' => 2, 'title' => 'S1E2']);
        SeriesEpisode::factory()->for($series)->create(['season_id' => $s1->id, 'episode_number' => 1, 'title' => 'S1E1']);
        SeriesEpisode::factory()->for($series)->create(['season_id' => $s1->id, 'episode_number' => 10, 'title' => 'S1E10']);
        SeriesEpisode::factory()->for($series)->create(['season_id' => $s2->id, 'episode_number' => 1, 'title' => 'S2E1']);
        SeriesEpisode::factory()->for($series)->create(['season_id' => $specials->id, 'episode_number' => 1, 'title' => 'Special']);
        // Draft episodes and draft-season episodes never enter the order.
        SeriesEpisode::factory()->for($series)->draft()->create(['season_id' => $s1->id, 'episode_number' => 3, 'title' => 'Draft']);
        $draftSeason = Season::factory()->for($series)->numbered(3)->draft()->create();
        SeriesEpisode::factory()->for($series)->create(['season_id' => $draftSeason->id, 'episode_number' => 1, 'title' => 'Hidden season ep']);

        return $series;
    }

    public function test_flat_catalog_order_is_seasons_ascending_with_specials_last(): void
    {
        $series = $this->buildTree();

        $titles = collect($this->getJson("/api/v1/series/{$series->slug}")->assertOk()->json('data.episodes'))
            ->pluck('title')
            ->all();

        $this->assertSame(['S1E1', 'S1E2', 'S1E10', 'S2E1', 'Special'], $titles);
    }

    public function test_season_structure_lists_specials_last_with_its_episodes(): void
    {
        $series = $this->buildTree();

        $seasons = $this->getJson("/api/v1/series/{$series->slug}")->assertOk()->json('data.seasons');

        $this->assertSame([1, 2, 0], array_column($seasons, 'season_number'));
        $this->assertSame(3, $seasons[0]['episode_count']);
        $this->assertSame(1, $seasons[1]['episode_count']);
        $this->assertSame(1, $seasons[2]['episode_count']);
    }

    public function test_numeric_ordering_not_lexicographic(): void
    {
        $series = $this->buildTree();

        $numbers = collect($this->getJson("/api/v1/series/{$series->slug}")->assertOk()->json('data.episodes'))
            ->pluck('episode_number')
            ->all();

        // S1: 1, 2, 10 (numeric, not 1, 10, 2), then S2E1, then Special E1.
        $this->assertSame([1, 2, 10, 1, 1], $numbers);
    }

    public function test_next_episode_boundary_crosses_seasons(): void
    {
        $series = $this->buildTree();

        $episodes = collect($this->getJson("/api/v1/series/{$series->slug}")->assertOk()->json('data.episodes'));

        // The episode after S1E10 (last of season 1) is S2E1.
        $index = $episodes->search(fn ($ep) => $ep['title'] === 'S1E10');
        $this->assertSame('S2E1', $episodes[$index + 1]['title']);

        // The last episode overall has no next; the Special is last.
        $this->assertSame('Special', $episodes->last()['title']);
    }

    public function test_season_scoped_episode_endpoint_orders_within_the_season(): void
    {
        $series = $this->buildTree();

        $numbers = collect($this->getJson("/api/v1/series/{$series->slug}/seasons/1/episodes")->assertOk()->json('data'))
            ->pluck('episode_number')
            ->all();

        $this->assertSame([1, 2, 10], $numbers);
    }
}
