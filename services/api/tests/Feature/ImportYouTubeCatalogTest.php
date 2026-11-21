<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\Movie;
use App\Models\Season;
use App\Models\Series;
use App\Models\SeriesEpisode;
use App\Models\User;
use App\Support\YouTubeCatalog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ApiHelpers;
use Tests\TestCase;

class ImportYouTubeCatalogTest extends TestCase
{
    use ApiHelpers;
    use RefreshDatabase;

    private array $paths = [];

    private function path(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'youtube-catalog-test-');
        $this->paths[] = $path;

        return $path;
    }

    protected function tearDown(): void
    {
        Movie::flushEventListeners();
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    private function row(array $overrides = []): array
    {
        return array_replace([
            'title' => 'သစ္စာစိမ်းစိမ်း', 'youtube_title' => 'သစ္စာစိမ်းစိမ်း Original title',
            'video_kind' => 'full_movie', 'artwork_url' => 'https://i.ytimg.com/vi/M7lc1UVf-VE/hqdefault.jpg',
            'youtube_url' => 'https://youtu.be/M7lc1UVf-VE?si=test',
            'channel_url' => 'https://www.youtube.com/@PyonePlayOfficial', 'series_playlist_url' => '',
            'artwork_type' => 'youtube_thumbnail', 'artwork_check' => 'verified', 'youtube_check' => 'channel_and_oembed_verified',
        ], $overrides);
    }

    private function inputs(array $rows, array $verificationOverrides = []): array
    {
        $csv = $this->path();
        $file = fopen($csv, 'wb');
        fwrite($file, "\xEF\xBB\xBF");
        fputcsv($file, YouTubeCatalog::COLUMNS, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($file, array_map(fn ($key) => $row[$key], YouTubeCatalog::COLUMNS), ',', '"', '');
        }
        fclose($file);
        $verification = $this->path();
        $report = array_replace([
            'source_sha256' => hash_file('sha256', $csv), 'checked_at' => now()->toIso8601String(),
            'rows' => array_map(fn ($row, $index) => [
                'row' => $index + 1, 'youtube_url' => $row['youtube_url'], 'status' => 'verified_metadata',
                'oembed' => ['provider_name' => 'YouTube', 'type' => 'video', 'author_name' => 'Pyone Play', 'author_url' => $row['channel_url'], 'title' => $row['youtube_title']],
                'artwork_http' => 200, 'artwork_content_type' => 'image/jpeg',
            ], $rows, array_keys($rows)),
        ], $verificationOverrides);
        file_put_contents($verification, json_encode($report));

        return ['--source-file' => $csv, '--batch' => 'test-batch', '--verification-report' => $verification];
    }

    private function runImport(array $args): array
    {
        $exit = Artisan::call('hyperline:import-youtube-catalog', $args);

        return [$exit, json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)];
    }

    public function test_verified_import_is_a_draft_with_original_metadata_and_no_invented_fields(): void
    {
        [$exit, $report] = $this->runImport($this->inputs([$this->row()]));
        $this->assertSame(0, $exit);
        $this->assertSame(['created_draft' => 1], $report['summary']['dispositions']);
        $movie = Movie::firstOrFail();
        $this->assertFalse($movie->published);
        $this->assertNull($movie->published_at);
        $this->assertNull($movie->synopsis);
        $this->assertNull($movie->duration_minutes);
        $this->assertNull($movie->release_year);
        $this->assertSame(0, $movie->genres()->count());
        $this->assertSame($this->row()['title'], $movie->title);
        $this->assertDatabaseHas('video_sources', ['kind' => 'youtube', 'url' => 'https://www.youtube.com/watch?v=M7lc1UVf-VE']);
        $metadata = AdminAuditLog::firstOrFail()->properties;
        $this->assertSame($this->row()['youtube_title'], $metadata['metadata']['youtube_title']);
        $this->assertSame('test-batch', $metadata['batch']);
        $this->assertArrayNotHasKey('youtube_url', $metadata['metadata']);
        $this->getJson('/api/v1/movies')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/movies/'.$movie->slug)->assertNotFound();
    }

    public function test_dry_run_uses_only_selects_and_does_not_write_audit_or_sequences(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = strtolower(ltrim($query->sql));
        });
        [$exit, $report] = $this->runImport($this->inputs([$this->row()]) + ['--dry-run' => true]);
        $this->assertSame(0, $exit);
        $this->assertSame('would_create_draft', $report['rows'][0]['status']);
        $this->assertNotEmpty($queries);
        foreach ($queries as $sql) {
            $this->assertStringStartsWith('select', $sql);
        }
        $this->assertDatabaseCount('movies', 0);
        $this->assertDatabaseCount('video_sources', 0);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_idempotency_across_batches_never_overwrites_published_or_existing_content(): void
    {
        $args = $this->inputs([$this->row()]);
        $this->assertSame(0, $this->runImport($args)[0]);
        $movie = Movie::firstOrFail();
        $movie->update(['published' => true, 'synopsis' => 'Preserved editorial content']);
        $before = $movie->fresh()->getAttributes();
        foreach (['test-batch', 'another-batch'] as $batch) {
            [$exit, $report] = $this->runImport(array_replace($args, ['--batch' => $batch]));
            $this->assertSame(0, $exit);
            $this->assertSame('existing_preserved', $report['rows'][0]['status']);
            $this->assertSame($before, $movie->fresh()->getAttributes());
        }
        $this->assertDatabaseCount('movies', 1);
        $this->assertDatabaseCount('video_sources', 1);
        $this->assertDatabaseCount('admin_audit_logs', 1);
    }

    public function test_normalized_legacy_duplicate_is_preserved_without_claiming_batch_ownership(): void
    {
        $movie = Movie::factory()->create(['published' => true]);
        $source = $movie->videoSources()->create(['label' => 'legacy', 'kind' => 'youtube', 'url' => 'https://www.youtube.com/embed/M7lc1UVf-VE']);
        DB::table('video_sources')->where('id', $source->id)->update(['url' => 'https://youtu.be/M7lc1UVf-VE?t=5']);
        [$exit, $report] = $this->runImport($this->inputs([$this->row()]));
        $this->assertSame(0, $exit);
        $this->assertSame('existing_preserved', $report['rows'][0]['status']);
        $this->assertDatabaseCount('movies', 1);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_invalid_or_duplicate_rows_abort_entire_import_before_any_write(): void
    {
        foreach ([
            $this->row(['youtube_url' => 'https://evil.test/watch?v=M7lc1UVf-VE']),
            $this->row(['artwork_url' => 'https://i.ytimg.com/vi/other-id/hqdefault.jpg']),
            $this->row(['series_playlist_url' => 'https://user@www.youtube.com/playlist?list=PLabcdefghijk']),
            $this->row(['series_playlist_url' => 'https://www.youtube.com/playlist?list=PLabcdefghijk&list=PLanotherone']),
            $this->row(['youtube_url' => 'https://www.youtube.com/watch?v=M7lc1UVf-VE']),
        ] as $invalid) {
            [$exit] = $this->runImport($this->inputs([$this->row(), $invalid]));
            $this->assertSame(1, $exit);
            $this->assertDatabaseCount('movies', 0);
            $this->assertDatabaseCount('admin_audit_logs', 0);
        }
    }

    public function test_csv_verified_claims_cannot_replace_fresh_independent_evidence(): void
    {
        $args = $this->inputs([$this->row()]);
        unset($args['--verification-report']);
        [$exit, $report] = $this->runImport($args);
        $this->assertSame(0, $exit);
        $this->assertSame('deferred_unverified', $report['rows'][0]['status']);
        $this->assertDatabaseCount('movies', 0);
        foreach (['source_sha256' => 'wrong', 'checked_at' => now()->subDays(31)->toIso8601String()] as $key => $value) {
            $this->assertSame(1, $this->runImport($this->inputs([$this->row()], [$key => $value]))[0]);
        }
    }

    public function test_episodes_and_supplemental_content_never_become_full_movies(): void
    {
        foreach (['series_episode', 'mini_episode', 'trailer', 'film_promo', 'fan_meet'] as $kind) {
            [$exit, $report] = $this->runImport($this->inputs([$this->row(['video_kind' => $kind])]));
            $this->assertSame(0, $exit);
            $this->assertSame(in_array($kind, ['series_episode', 'mini_episode']) ? 'deferred_relationship' : 'deferred_non_movie', $report['rows'][0]['status']);
        }
        $this->assertDatabaseCount('movies', 0);
        $this->assertDatabaseCount('series', 0);
        $this->assertDatabaseCount('seasons', 0);
        $this->assertDatabaseCount('series_episodes', 0);
    }

    public function test_episode_requires_reviewed_draft_parent_mapping_and_metadata_number(): void
    {
        $series = Series::factory()->create(['title' => $this->row()['title'], 'published' => false]);
        $season = Season::factory()->create(['series_id' => $series->id, 'published' => false]);
        $map = $this->path();
        file_put_contents($map, json_encode(['M7lc1UVf-VE' => ['series_id' => $series->id, 'season_id' => $season->id, 'episode_number' => 8, 'evidence' => 'Explicit supplied title and reviewed parent IDs']]));
        $args = $this->inputs([$this->row(['video_kind' => 'mini_episode', 'youtube_title' => 'ဇာတ်သိမ်းပိုင်း/၈'])]) + ['--episode-map' => $map];
        $season->update(['published' => true]);
        $this->assertSame('deferred_relationship', $this->runImport($args)[1]['rows'][0]['status']);
        $season->update(['published' => false]);
        $this->assertSame(0, $this->runImport($args)[0]);
        $episode = SeriesEpisode::firstOrFail();
        $this->assertFalse($episode->published);
        $this->assertSame(8, $episode->episode_number);
        $this->assertSame($season->id, $episode->season_id);
        $this->assertDatabaseCount('movies', 0);
        $this->assertDatabaseCount('series', 1);
        $this->assertDatabaseCount('seasons', 1);
    }

    public function test_remote_artwork_failure_uses_fallback_and_retains_supplied_metadata(): void
    {
        $args = $this->inputs([$this->row()]);
        $data = json_decode(file_get_contents($args['--verification-report']), true);
        $data['rows'][0]['artwork_http'] = 404;
        file_put_contents($args['--verification-report'], json_encode($data));
        $this->assertSame(0, $this->runImport($args)[0]);
        $this->assertNull(Movie::firstOrFail()->poster_url);
        $this->assertSame($this->row()['artwork_url'], AdminAuditLog::firstOrFail()->properties['metadata']['artwork_url']);
    }

    public function test_unicode_search_publication_and_cms_attribution_survive_source_replacement(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->administrator()->create();
        $this->assertSame(0, $this->runImport($this->inputs([$this->row()]))[0]);
        $movie = Movie::firstOrFail();
        $this->withHeaders($this->bearer($this->adminToken($admin)))->getJson('/api/v1/admin/movies?published=0')
            ->assertOk()->assertJsonPath('data.0.published', false)->assertJsonPath('data.0.youtube.youtube_title', $this->row()['youtube_title']);
        $movie->update(['published' => true]); // Isolated test-only publication.
        $this->getJson('/api/v1/movies?q='.urlencode('သစ္စာ'))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/movies/'.$movie->slug)->assertOk()
            ->assertJsonPath('data.youtube.channel_name', 'Pyone Play')->assertJsonMissingPath('data.sources.0.url')
            ->assertJsonMissingPath('data.youtube.video_id')->assertJsonMissingPath('data.youtube.batch');
        $movie->videoSources()->delete();
        $movie->videoSources()->create(['kind' => 'youtube', 'url' => $this->row()['youtube_url'], 'label' => 'replacement', 'enabled' => true]);
        AdminAuditLog::record($admin, 'movie.updated', $movie, 'Later CMS edit must not hide import attribution');
        $this->assertSame('Pyone Play', $movie->fresh()->youtubeAttribution()['channel_name']);
        $movie->videoSources()->first()->update(['url' => 'https://www.youtube.com/watch?v=abcdefghijk']);
        $this->assertSame(['platform' => 'YouTube'], $movie->fresh()->youtubeAttribution());
    }

    public function test_batch_reuse_with_different_csv_and_report_overwrite_are_rejected(): void
    {
        $this->assertSame(0, $this->runImport($this->inputs([$this->row()]))[0]);
        $this->assertSame(1, $this->runImport($this->inputs([$this->row(['title' => 'Changed'])]))[0]);
        $path = $this->path();
        file_put_contents($path, 'Preserved');
        $this->assertSame(1, $this->runImport($this->inputs([$this->row()]) + ['--report' => $path])[0]);
        $this->assertSame('Preserved', file_get_contents($path));
        $this->assertDatabaseCount('movies', 1);
    }

    public function test_database_failure_rolls_back_entire_batch_and_reports_no_created_ids(): void
    {
        $rows = [$this->row(), $this->row(['title' => 'Fail second', 'youtube_url' => 'https://youtu.be/abcdefghijk', 'artwork_url' => 'https://i.ytimg.com/vi/abcdefghijk/hqdefault.jpg'])];
        Movie::creating(function ($movie) {
            if ($movie->title === 'Fail second') {
                throw new \RuntimeException('Injected isolated write failure');
            }
        });
        [$exit, $report] = $this->runImport($this->inputs($rows));
        $this->assertSame(1, $exit);
        $this->assertSame('rolled_back', $report['rows'][0]['status']);
        $this->assertArrayNotHasKey('created', $report['rows'][0]);
        $this->assertDatabaseCount('movies', 0);
        $this->assertDatabaseCount('video_sources', 0);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }
}
