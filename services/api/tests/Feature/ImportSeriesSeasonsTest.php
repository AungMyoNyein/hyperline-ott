<?php

namespace Tests\Feature;

use App\Models\Season;
use App\Models\Series;
use App\Models\SeriesEpisode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Season-aware import behavior: declared seasons, per-episode season
 * assignment, on-demand seasons, specials, idempotency and flat-manifest
 * backward compatibility.
 */
class ImportSeriesSeasonsTest extends TestCase
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

    private function writeManifest(array $manifest): string
    {
        file_put_contents($this->manifestPath, json_encode($manifest));

        return $this->manifestPath;
    }

    private function url(int $n): string
    {
        return "https://cdn.example.test/vod/show/s{$n}/playlist.m3u8";
    }

    public function test_declared_seasons_and_per_episode_assignment_are_imported(): void
    {
        $manifest = $this->writeManifest([
            'title' => 'Seasoned show',
            'slug' => 'seasoned-show',
            'seasons' => [
                ['number' => 1, 'title' => 'First run'],
                ['number' => 2, 'title' => 'Sophomore'],
                ['number' => 0, 'title' => 'Specials'],
            ],
            'episodes' => [
                ['season' => 1, 'number' => 1, 'hls_url' => $this->url(1)],
                ['season' => 1, 'number' => 2, 'hls_url' => $this->url(2)],
                ['season' => 2, 'number' => 1, 'hls_url' => $this->url(3)],
                ['season' => 0, 'number' => 1, 'title' => 'Behind the scenes', 'hls_url' => $this->url(4)],
            ],
        ]);

        $this->artisan('hyperline:import-series', ['manifest' => $manifest])->assertSuccessful();

        $series = Series::firstOrFail();
        $this->assertSame(3, $series->seasons()->count());
        $this->assertSame('First run', $series->seasons()->where('season_number', 1)->value('title'));

        $s1 = $series->seasons()->where('season_number', 1)->firstOrFail();
        $s2 = $series->seasons()->where('season_number', 2)->firstOrFail();
        $s0 = $series->seasons()->where('season_number', 0)->firstOrFail();
        $this->assertSame(2, $s1->episodes()->count());
        $this->assertSame(1, $s2->episodes()->count());
        $this->assertSame(1, $s0->episodes()->count());
        $this->assertTrue($s0->isSpecials());

        // Episode 1 exists independently in three seasons.
        $this->assertSame(3, SeriesEpisode::where('episode_number', 1)->count());
    }

    public function test_flat_manifest_without_seasons_lands_in_season_one(): void
    {
        $manifest = $this->writeManifest([
            'title' => 'Flat show',
            'episodes' => [
                ['number' => 1, 'hls_url' => $this->url(1)],
                ['number' => 2, 'hls_url' => $this->url(2)],
            ],
        ]);

        $this->artisan('hyperline:import-series', ['manifest' => $manifest])->assertSuccessful();

        $series = Series::firstOrFail();
        $this->assertSame(1, $series->seasons()->count());
        $this->assertSame(1, $series->seasons()->firstOrFail()->season_number);
        $this->assertSame(2, $series->seasons()->firstOrFail()->episodes()->count());
    }

    public function test_episodes_referencing_undeclared_seasons_create_them_on_demand(): void
    {
        $manifest = $this->writeManifest([
            'title' => 'Ondemand show',
            'seasons' => [['number' => 1]],
            'episodes' => [
                ['number' => 1, 'hls_url' => $this->url(1)],
                ['season' => 7, 'number' => 1, 'hls_url' => $this->url(2)],
            ],
        ]);

        $this->artisan('hyperline:import-series', ['manifest' => $manifest])->assertSuccessful();

        $series = Series::firstOrFail();
        $this->assertTrue($series->seasons()->where('season_number', 7)->exists());
    }

    public function test_duplicate_number_within_a_season_is_rejected_but_allowed_across_seasons(): void
    {
        $bad = $this->writeManifest([
            'title' => 'Dup show',
            'episodes' => [
                ['season' => 1, 'number' => 1, 'hls_url' => $this->url(1)],
                ['season' => 1, 'number' => 1, 'hls_url' => $this->url(2)],
            ],
        ]);

        $this->artisan('hyperline:import-series', ['manifest' => $bad])->assertFailed();
        $this->assertDatabaseCount('series', 0);
    }

    public function test_season_import_is_idempotent(): void
    {
        $manifest = $this->writeManifest([
            'title' => 'Idem show',
            'seasons' => [['number' => 1, 'title' => 'One'], ['number' => 2, 'title' => 'Two']],
            'episodes' => [
                ['season' => 1, 'number' => 1, 'hls_url' => $this->url(1)],
                ['season' => 2, 'number' => 1, 'hls_url' => $this->url(2)],
            ],
        ]);

        $this->artisan('hyperline:import-series', ['manifest' => $manifest])->assertSuccessful();
        $seasonIds = Season::orderBy('id')->pluck('id')->all();
        $episodeIds = SeriesEpisode::orderBy('id')->pluck('id')->all();

        $second = $this->artisan('hyperline:import-series', ['manifest' => $manifest]);
        $second->assertSuccessful();
        $second->expectsOutputToContain('"episodes_created": 0');

        $this->assertSame($seasonIds, Season::orderBy('id')->pluck('id')->all());
        $this->assertSame($episodeIds, SeriesEpisode::orderBy('id')->pluck('id')->all());
    }

    public function test_dry_run_with_seasons_writes_nothing(): void
    {
        $manifest = $this->writeManifest([
            'title' => 'Dry show',
            'seasons' => [['number' => 1], ['number' => 2]],
            'episodes' => [['season' => 2, 'number' => 1, 'hls_url' => $this->url(1)]],
        ]);

        $this->artisan('hyperline:import-series', ['manifest' => $manifest, '--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('"dry_run": true');

        $this->assertDatabaseCount('series', 0);
        $this->assertDatabaseCount('seasons', 0);
        $this->assertDatabaseCount('series_episodes', 0);
    }

    public function test_import_over_existing_flat_data_attaches_episodes_to_season_one(): void
    {
        // Simulate a Phase 1 series backfilled by the migration: episodes
        // already live in season 1 with stable ids.
        $series = Series::factory()->create(['slug' => 'legacy-show', 'title' => 'Legacy show']);
        $season = Season::factory()->for($series)->numbered(1)->create();
        $existing = SeriesEpisode::factory()->for($series)->create(['season_id' => $season->id, 'episode_number' => 1]);

        $manifest = $this->writeManifest([
            'title' => 'Legacy show',
            'slug' => 'legacy-show',
            'episodes' => [
                ['number' => 1, 'hls_url' => $this->url(1)],
                ['number' => 2, 'hls_url' => $this->url(2)],
            ],
        ]);

        $this->artisan('hyperline:import-series', ['manifest' => $manifest])->assertSuccessful();

        $this->assertSame($existing->id, $existing->fresh()->id);
        $this->assertSame(2, $season->episodes()->count());
        $this->assertDatabaseCount('seasons', 1);
    }
}
