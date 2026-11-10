<?php

namespace Tests\Feature;

use App\Models\Series;
use App\Models\SeriesEpisode;
use App\Models\User;
use App\Models\VideoSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ApiHelpers;
use Tests\TestCase;

class SeriesCatalogTest extends TestCase
{
    use ApiHelpers;
    use RefreshDatabase;

    private function seriesWithEpisodes(int $count = 3, array $seriesAttributes = []): Series
    {
        $series = Series::factory()->create($seriesAttributes);

        foreach (range(1, $count) as $number) {
            $episode = SeriesEpisode::factory()->for($series)->create(['episode_number' => $number]);
            VideoSource::factory()->forEpisode($episode)->create();
        }

        return $series->load('episodes.videoSources');
    }

    public function test_public_series_index_lists_only_published_series(): void
    {
        $published = $this->seriesWithEpisodes(3);
        Series::factory()->draft()->create(['title' => 'Hidden Series']);

        $response = $this->getJson('/api/v1/series')->assertOk();

        $slugs = collect($response->json('data'))->pluck('slug');
        $this->assertTrue($slugs->contains($published->slug));
        $this->assertFalse($slugs->contains('hidden-series'));
        $response->assertJsonPath('data.0.type', 'series');
    }

    public function test_series_index_exposes_published_episode_counts_without_urls(): void
    {
        $series = $this->seriesWithEpisodes(3);
        SeriesEpisode::factory()->for($series)->draft()->create(['episode_number' => 4]);

        $response = $this->getJson('/api/v1/series')->assertOk();

        $row = collect($response->json('data'))->firstWhere('slug', $series->slug);
        $this->assertSame(3, $row['episode_count']);
        $this->assertStringNotContainsString('m3u8', $response->getContent());
        $this->assertStringNotContainsString('/vod/', $response->getContent());
    }

    public function test_series_detail_returns_episodes_ordered_by_number(): void
    {
        $series = Series::factory()->create();
        foreach ([3, 1, 2] as $number) {
            $episode = SeriesEpisode::factory()->for($series)->create(['episode_number' => $number]);
            VideoSource::factory()->forEpisode($episode)->create(['url' => "https://cdn.example.test/vod/ep{$number}/playlist.m3u8"]);
        }

        $response = $this->getJson('/api/v1/series/'.$series->slug)->assertOk();

        $this->assertSame([1, 2, 3], collect($response->json('data.episodes'))->pluck('episode_number')->all());
        $response->assertJsonPath('data.episodes.0.playable', true);
        $response->assertJsonPath('data.episodes.0.sources.0.label', 'main');
        $this->assertStringNotContainsString('m3u8', $response->getContent());
        $this->assertStringNotContainsString('/vod/', $response->getContent());
    }

    public function test_unpublished_series_and_episodes_are_hidden_from_the_public(): void
    {
        $series = $this->seriesWithEpisodes(2, ['published' => false, 'slug' => 'draft-series']);

        $this->getJson('/api/v1/series/draft-series')->assertNotFound();

        $series->update(['published' => true]);
        SeriesEpisode::factory()->for($series)->draft()->create(['episode_number' => 3]);

        $response = $this->getJson('/api/v1/series/draft-series')->assertOk();
        $this->assertSame([1, 2], collect($response->json('data.episodes'))->pluck('episode_number')->all());
    }

    public function test_administrator_sees_draft_series_and_draft_episodes(): void
    {
        $admin = User::factory()->administrator()->create();
        $series = $this->seriesWithEpisodes(1, ['published' => false, 'slug' => 'draft-series']);
        SeriesEpisode::factory()->for($series)->draft()->create(['episode_number' => 2]);

        $response = $this->withHeaders($this->bearer($this->adminToken($admin)))
            ->getJson('/api/v1/series/draft-series')
            ->assertOk();

        $this->assertSame([1, 2], collect($response->json('data.episodes'))->pluck('episode_number')->all());
    }

    public function test_home_and_search_include_series_additively(): void
    {
        $series = $this->seriesWithEpisodes(2, ['title' => 'Medical Legends']);

        $home = $this->getJson('/api/v1/home')->assertOk();
        $this->assertArrayHasKey('series', $home->json('data'));
        $this->assertSame($series->slug, collect($home->json('data.series'))->pluck('slug')->first());

        $search = $this->getJson('/api/v1/search?q=Medical')->assertOk();
        $this->assertSame($series->slug, collect($search->json('data.series'))->pluck('slug')->first());
    }

    public function test_series_detail_never_discloses_disabled_sources(): void
    {
        $series = Series::factory()->create();
        $episode = SeriesEpisode::factory()->for($series)->create(['episode_number' => 1]);
        VideoSource::factory()->forEpisode($episode)->disabled()->create();

        $response = $this->getJson('/api/v1/series/'.$series->slug)->assertOk();
        $response->assertJsonPath('data.episodes.0.playable', false);
        $response->assertJsonPath('data.episodes.0.sources', []);
    }
}
