<?php

namespace Tests\Feature;

use App\Models\Genre;
use App\Models\Movie;
use App\Models\PlaybackSession;
use App\Models\User;
use App\Models\VideoSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ApiHelpers;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use ApiHelpers;
    use RefreshDatabase;

    public function test_lists_only_published_movies_with_pagination(): void
    {
        Movie::factory()->count(3)->create();
        Movie::factory()->draft()->create(['title' => 'Secret Premiere']);

        $response = $this->getJson('/api/v1/movies');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'slug', 'title', 'genres', 'playable', 'poster_url']],
                'links',
                'meta' => ['current_page', 'per_page', 'total'],
            ]);

        $this->assertStringNotContainsString('Secret Premiere', $response->getContent());
    }

    public function test_public_endpoints_never_expose_stream_locations(): void
    {
        $movie = Movie::factory()->create();
        VideoSource::factory()->for($movie)->create();

        $urls = [
            '/api/v1/movies',
            '/api/v1/movies/'.$movie->slug,
            '/api/v1/home',
            '/api/v1/search?q='.urlencode(substr($movie->title, 0, 5)),
            '/api/v1/genres',
        ];

        foreach ($urls as $url) {
            $response = $this->getJson($url)->assertOk();

            $this->assertStringNotContainsString('m3u8', $response->getContent(), $url.' leaked a stream location');
            $this->assertStringNotContainsString('cdn.example.test/vod', $response->getContent(), $url.' leaked a stream location');
        }
    }

    public function test_detail_lists_source_metadata_without_urls(): void
    {
        $movie = Movie::factory()->create();
        VideoSource::factory()->for($movie)->create(['label' => 'main', 'quality' => '720p']);

        $response = $this->getJson('/api/v1/movies/'.$movie->slug)->assertOk();

        $response->assertJsonPath('data.sources.0.label', 'main')
            ->assertJsonPath('data.sources.0.quality', '720p')
            ->assertJsonPath('data.sources.0.default', true)
            ->assertJsonPath('data.playable', true);

        $this->assertArrayNotHasKey('url', $response->json('data.sources.0'));
        $this->assertStringNotContainsString('m3u8', $response->getContent());
    }

    public function test_filters_by_genre_and_feature_flag_and_year(): void
    {
        $action = Genre::factory()->create(['name' => 'Action', 'slug' => 'action']);
        $drama = Genre::factory()->create(['name' => 'Drama', 'slug' => 'drama']);

        $actionMovie = Movie::factory()->create(['release_year' => 1999]);
        $actionMovie->genres()->attach($action);

        $dramaMovie = Movie::factory()->create(['release_year' => 2010, 'featured' => true]);
        $dramaMovie->genres()->attach($drama);

        $this->getJson('/api/v1/movies?genre=action')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $actionMovie->id);

        $this->getJson('/api/v1/movies?genre=drama&featured=1')
            ->assertOk()->assertJsonPath('data.0.id', $dramaMovie->id);

        $this->getJson('/api/v1/movies?year=1999')
            ->assertOk()->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/movies?genre=does-not-exist')
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');
    }

    public function test_search_matches_title_and_synopsis_case_insensitively(): void
    {
        // Distinctive tokens keep faker-generated synopses from matching by accident.
        Movie::factory()->create(['title' => 'The Zephyr Protocol', 'synopsis' => 'Quantum drift.']);
        Movie::factory()->create(['title' => 'Quiet Nights', 'synopsis' => 'A story about QUANTUM drift at sea.']);
        Movie::factory()->draft()->create(['title' => 'Zephyr Draft', 'synopsis' => 'Quantum embargo.']);

        $this->getJson('/api/v1/search?q=zephyr')
            ->assertOk()
            ->assertJsonCount(1, 'data.movies')
            ->assertJsonPath('data.movies.0.title', 'The Zephyr Protocol');

        $this->getJson('/api/v1/search?q=quantum')
            ->assertOk()
            ->assertJsonCount(2, 'data.movies');

        $this->getJson('/api/v1/search?q=a')
            ->assertStatus(422);
    }

    public function test_genres_endpoint_counts_published_titles_only(): void
    {
        $genre = Genre::factory()->create(['name' => 'Comedy', 'slug' => 'comedy']);

        $published = Movie::factory()->create();
        $published->genres()->attach($genre);

        $draft = Movie::factory()->draft()->create();
        $draft->genres()->attach($genre);

        $this->getJson('/api/v1/genres')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Comedy')
            ->assertJsonPath('data.0.movies_count', 1);
    }

    public function test_home_returns_rows_and_hides_continue_watching_for_guests(): void
    {
        $featured = Movie::factory()->featured()->create();
        $latest = Movie::factory()->create();

        $response = $this->getJson('/api/v1/home')->assertOk();

        $response->assertJsonPath('data.hero.id', $featured->id)
            ->assertJsonStructure([
                'data' => [
                    'hero' => ['id', 'title', 'slug', 'playable'],
                    'featured',
                    'trending',
                    'latest',
                    'continue_watching',
                ],
            ]);

        $this->assertSame([], $response->json('data.continue_watching'));
        $this->assertStringNotContainsString('m3u8', $response->getContent());
    }

    public function test_trending_orders_by_recent_playback_sessions(): void
    {
        $viewer = User::factory()->create();
        $quiet = Movie::factory()->create();
        $popular = Movie::factory()->create();

        PlaybackSession::factory()->for($popular)->for($viewer)->create();
        PlaybackSession::factory()->for($quiet)->for($viewer)->create();
        PlaybackSession::factory()->for($popular)->for($viewer)->create();

        $ids = collect($this->getJson('/api/v1/home')->assertOk()->json('data.trending'))->pluck('id');

        $this->assertSame($popular->id, $ids->first());
    }

    public function test_draft_detail_is_not_found_for_guests_but_visible_to_administrators(): void
    {
        $admin = User::factory()->administrator()->create();
        $draft = Movie::factory()->draft()->create();
        VideoSource::factory()->for($draft)->create();

        $this->getJson('/api/v1/movies/'.$draft->slug)->assertStatus(404)->assertJsonPath('code', 'not_found');

        $this->withHeaders($this->bearer($this->adminToken($admin)))
            ->getJson('/api/v1/movies/'.$draft->slug)
            ->assertOk()
            ->assertJsonPath('data.slug', $draft->slug)
            ->assertJsonPath('data.published_at', null);
    }
}
