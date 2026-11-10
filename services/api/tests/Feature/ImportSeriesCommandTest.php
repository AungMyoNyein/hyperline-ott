<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\Series;
use App\Models\SeriesEpisode;
use App\Models\VideoSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportSeriesCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manifestPath = tempnam(sys_get_temp_dir(), 'series-manifest-').'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->manifestPath);
        parent::tearDown();
    }

    private function writeManifest(array $overrides = []): string
    {
        $manifest = array_merge([
            'title' => 'King of Medical Martial Arts',
            'slug' => 'king-of-medical-martial-arts',
            'category' => 'vertical',
            'language' => 'Chinese',
            'episodes' => [
                ['number' => 1, 'hls_url' => 'https://cdn.example.test/Vibe/Vertical_Series/01/playlist.m3u8'],
                ['number' => 2, 'title' => 'Episode 2', 'hls_url' => 'https://cdn.example.test/Vibe/Vertical_Series/02/playlist.m3u8'],
                ['number' => 3, 'hls_url' => 'https://cdn.example.test/Vibe/Vertical_Series/03/playlist.m3u8'],
            ],
        ], $overrides);

        file_put_contents($this->manifestPath, json_encode($manifest));

        return $this->manifestPath;
    }

    public function test_import_creates_one_draft_series_with_ordered_episodes_and_sources(): void
    {
        $this->artisan('hyperline:import-series', ['manifest' => $this->writeManifest()])
            ->assertSuccessful();

        $this->assertDatabaseCount('series', 1);
        $this->assertDatabaseCount('series_episodes', 3);
        $this->assertDatabaseCount('video_sources', 3);

        $series = Series::firstOrFail();
        $this->assertFalse($series->published);
        $this->assertSame('vertical', $series->category);
        $this->assertSame('Chinese', $series->language);
        $this->assertSame([1, 2, 3], $series->episodes()->ordered()->pluck('episode_number')->all());
        $this->assertFalse((bool) $series->episodes()->first()->published);

        $source = VideoSource::where('episode_id', $series->episodes()->first()->id)->firstOrFail();
        $this->assertSame('main', $source->label);
        $this->assertSame('hls', $source->kind);
        $this->assertNull($source->movie_id);
        $this->assertSame('Chinese', $source->language);
    }

    public function test_reimport_is_idempotent_and_creates_no_duplicates(): void
    {
        $manifest = $this->writeManifest();

        $this->artisan('hyperline:import-series', ['manifest' => $manifest])->assertSuccessful();
        $firstIds = SeriesEpisode::orderBy('id')->pluck('id')->all();
        $sourceIds = VideoSource::orderBy('id')->pluck('id')->all();

        $second = $this->artisan('hyperline:import-series', ['manifest' => $manifest]);
        $second->assertSuccessful();
        $second->expectsOutputToContain('"episodes_created": 0');

        $this->assertDatabaseCount('series', 1);
        $this->assertDatabaseCount('series_episodes', 3);
        $this->assertDatabaseCount('video_sources', 3);
        $this->assertSame($firstIds, SeriesEpisode::orderBy('id')->pluck('id')->all());
        $this->assertSame($sourceIds, VideoSource::orderBy('id')->pluck('id')->all());
    }

    public function test_reimport_updates_changed_urls_in_place(): void
    {
        $this->artisan('hyperline:import-series', ['manifest' => $this->writeManifest()])->assertSuccessful();

        $updated = $this->writeManifest(['episodes' => [
            ['number' => 1, 'hls_url' => 'https://other-cdn.example.test/Vibe/Vertical_Series/01/playlist.m3u8'],
            ['number' => 2, 'hls_url' => 'https://cdn.example.test/Vibe/Vertical_Series/02/playlist.m3u8'],
            ['number' => 3, 'hls_url' => 'https://cdn.example.test/Vibe/Vertical_Series/03/playlist.m3u8'],
        ]]);

        $this->artisan('hyperline:import-series', ['manifest' => $updated])->assertSuccessful();

        $this->assertDatabaseCount('series_episodes', 3);
        $this->assertDatabaseCount('video_sources', 3);
        $episode = SeriesEpisode::where('episode_number', 1)->firstOrFail();
        $this->assertStringStartsWith(
            'https://other-cdn.example.test',
            VideoSource::where('episode_id', $episode->id)->firstOrFail()->url,
        );
    }

    public function test_dry_run_writes_nothing(): void
    {
        $manifest = $this->writeManifest();

        $this->artisan('hyperline:import-series', ['manifest' => $manifest, '--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('"dry_run": true');

        $this->assertDatabaseCount('series', 0);
        $this->assertDatabaseCount('series_episodes', 0);
        $this->assertDatabaseCount('video_sources', 0);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_publish_flag_publishes_series_and_episodes(): void
    {
        $manifest = $this->writeManifest();
        $this->artisan('hyperline:import-series', ['manifest' => $manifest])->assertSuccessful();

        $this->artisan('hyperline:import-series', ['manifest' => $manifest, '--publish' => true])
            ->assertSuccessful();

        $series = Series::firstOrFail();
        $this->assertTrue($series->published);
        $this->assertNotNull($series->published_at);
        $this->assertSame(3, SeriesEpisode::where('published', true)->count());
    }

    public function test_import_never_touches_movies(): void
    {
        $movie = Movie::factory()->create();
        VideoSource::factory()->for($movie)->create();
        $movieHash = $movie->fresh()->toJson();
        $sourceHash = VideoSource::where('movie_id', $movie->id)->firstOrFail()->toJson();

        $this->artisan('hyperline:import-series', ['manifest' => $this->writeManifest()])->assertSuccessful();
        $this->artisan('hyperline:import-series', ['manifest' => $this->writeManifest(), '--publish' => true])->assertSuccessful();

        $this->assertDatabaseCount('movies', 1);
        $this->assertSame($movieHash, $movie->fresh()->toJson());
        $this->assertSame($sourceHash, VideoSource::where('movie_id', $movie->id)->firstOrFail()->toJson());
    }

    public function test_invalid_manifests_are_rejected(): void
    {
        // Missing episodes
        $this->artisan('hyperline:import-series', ['manifest' => $this->writeManifest(['episodes' => []])])
            ->assertFailed();

        // Duplicate episode numbers
        $this->artisan('hyperline:import-series', ['manifest' => $this->writeManifest(['episodes' => [
            ['number' => 1, 'hls_url' => 'https://cdn.example.test/a/playlist.m3u8'],
            ['number' => 1, 'hls_url' => 'https://cdn.example.test/b/playlist.m3u8'],
        ]])])->assertFailed();

        // Private host rejected by the SSRF guard rule
        $this->artisan('hyperline:import-series', ['manifest' => $this->writeManifest(['episodes' => [
            ['number' => 1, 'hls_url' => 'https://private.example.test/a/playlist.m3u8'],
        ]])])->assertFailed();

        // Non-HTTPS scheme rejected
        $this->artisan('hyperline:import-series', ['manifest' => $this->writeManifest(['episodes' => [
            ['number' => 1, 'hls_url' => 'http://cdn.example.test/a/playlist.m3u8'],
        ]])])->assertFailed();

        $this->assertDatabaseCount('series', 0);
        $this->assertDatabaseCount('series_episodes', 0);
    }

    public function test_missing_manifest_file_fails_cleanly(): void
    {
        $this->artisan('hyperline:import-series', ['manifest' => '/nonexistent/manifest.json'])
            ->assertFailed();
    }
}
