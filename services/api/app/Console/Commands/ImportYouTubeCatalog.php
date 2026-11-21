<?php

namespace App\Console\Commands;

use App\Models\AdminAuditLog;
use App\Models\Movie;
use App\Models\Season;
use App\Models\SeriesEpisode;
use App\Models\VideoSource;
use App\Rules\YouTubeUrl;
use App\Support\YouTubeCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class ImportYouTubeCatalog extends Command
{
    protected $signature = 'hyperline:import-youtube-catalog
        {--source-file= : UTF-8 CSV with the documented ten columns}
        {--batch= : Explicit immutable import batch identifier}
        {--verification-report= : Fresh metadata-only verification JSON bound to this CSV SHA-256}
        {--episode-map= : Reviewed video-ID to existing draft series/season/episode-number mapping JSON}
        {--dry-run : Plan with SELECTs only; no inserts, updates, audit rows or sequence changes}
        {--report= : New JSON report path; never overwrite an existing file}';

    protected $description = 'Validate and import independently verified YouTube catalog rows as drafts only; never update existing content';

    public function handle(): int
    {
        $report = ['dry_run' => (bool) $this->option('dry-run'), 'rows' => []];
        $output = null;
        try {
            $path = (string) $this->option('source-file');
            $batch = (string) $this->option('batch');
            if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,79}$/D', $batch)) {
                throw new InvalidArgumentException('An explicit --batch of 1-80 safe characters is required.');
            }
            $rows = YouTubeCatalog::read($path);
            $sha = hash_file('sha256', $path);
            $report += ['batch' => $batch, 'source_sha256' => $sha];
            $evidence = $this->evidence($sha);
            $maps = $this->option('episode-map') ? $this->json((string) $this->option('episode-map')) : [];
            $reportPath = (string) $this->option('report');
            if ($reportPath !== '') {
                // Reserve the report before any DB mutation; protect input/sealed files.
                $output = @fopen($reportPath, 'x');
                if ($output === false) {
                    throw new InvalidArgumentException('Report path must be new and writable.');
                }
                chmod($reportPath, 0600);
            }
            $seen = [];
            foreach ($rows as $index => $row) {
                $validation = YouTubeCatalog::validate($row);
                $id = $validation['video_id'];
                if ($id !== null && isset($seen[$id])) {
                    $validation['errors'][] = 'Duplicate normalized video ID within CSV; first data row '.$seen[$id].'.';
                }
                $seen[$id ?? 'invalid-'.$index] ??= $index + 1;
                $verified = YouTubeCatalog::metadataVerified($row, $evidence[$index + 1] ?? null);
                $report['rows'][] = [
                    'row' => $index + 1, 'metadata' => $row, 'video_id' => $id,
                    'validation' => $validation['errors'] === [] ? 'valid' : 'invalid',
                    'errors' => $validation['errors'], 'warnings' => $validation['warnings'],
                    'verification' => $verified ? 'verified_metadata' : 'unverified',
                    'artwork' => $this->usableArtwork($row, $evidence[$index + 1] ?? null) ? 'remote_thumbnail_head_ok' : 'fallback_required',
                    'status' => $validation['errors'] !== [] ? 'invalid' : (! $verified ? 'deferred_unverified' : 'pending'),
                ];
            }
            if (collect($report['rows'])->contains(fn ($r) => $r['validation'] === 'invalid')) {
                throw new InvalidArgumentException('Invalid CSV rows; no records written. Fix the row report and retry.');
            }
            $operation = function () use (&$report, $maps, $evidence, $batch, $sha): void {
                if (! $report['dry_run'] && DB::getDriverName() === 'pgsql') {
                    // Serialize this importer across batches without adding schema or
                    // relying on a Laravel cache connected to shared live Redis.
                    DB::select('select pg_advisory_xact_lock(?, ?)', [912411, 20261120]);
                }
                $previous = AdminAuditLog::query()->where('action', 'catalog.youtube.imported')->where('properties->batch', $batch)->get();
                if ($previous->contains(fn ($log) => ($log->properties['source_sha256'] ?? null) !== $sha)) {
                    throw new InvalidArgumentException('Batch already belongs to a different CSV SHA-256.');
                }
                $existing = [];
                foreach (VideoSource::query()->where('kind', 'youtube')->get(['id', 'url', 'kind', 'movie_id', 'episode_id']) as $source) {
                    $id = YouTubeUrl::videoId($source->url);
                    if ($id !== null) {
                        $existing[$id][] = $source->id;
                    }
                }
                $episodeKeys = [];
                foreach ($report['rows'] as &$entry) {
                    $row = $entry['metadata'];
                    $id = $entry['video_id'];
                    if (isset($existing[$id])) {
                        $entry['status'] = 'existing_preserved';
                        $entry['existing_source_ids'] = $existing[$id];

                        continue;
                    }
                    if ($entry['status'] !== 'pending') {
                        continue;
                    }
                    if (in_array($row['video_kind'], ['trailer', 'film_promo', 'fan_meet'], true)) {
                        $entry['status'] = 'deferred_non_movie';
                        $entry['warnings'][] = 'Classified supplemental content; no standalone Movie record is created.';

                        continue;
                    }
                    $season = null;
                    if (in_array($row['video_kind'], ['series_episode', 'mini_episode'], true)) {
                        $map = $maps[$id] ?? [];
                        $season = isset($map['season_id']) ? Season::with('series')->find($map['season_id']) : null;
                        $number = YouTubeCatalog::episodeNumber($row['youtube_title']);
                        if (! $season || ! is_int($map['series_id'] ?? null) || $season->series_id !== $map['series_id'] || $season->published || $season->series->published || $season->series->title !== $row['title'] || ! is_string($map['evidence'] ?? null) || trim($map['evidence']) === '' || ! is_int($map['episode_number'] ?? null) || $number === null || $number !== $map['episode_number'] || $number > 65535) {
                            $entry['status'] = 'deferred_relationship';
                            $entry['warnings'][] = 'Requires reviewed existing draft series/season mapping and an unambiguous episode number from the original title; no season is invented.';

                            continue;
                        }
                        $key = $season->id.':'.$number;
                        if (isset($episodeKeys[$key]) || SeriesEpisode::where('season_id', $season->id)->where('episode_number', $number)->exists()) {
                            $entry['status'] = 'deferred_episode_conflict';

                            continue;
                        }
                        $episodeKeys[$key] = true;
                        $entry['relationship'] = $map;
                    }
                    if ($row['video_kind'] === 'full_movie' && Movie::where('slug', 'youtube-'.hash('sha256', $id))->exists()) {
                        $entry['status'] = 'deferred_owner_conflict';

                        continue;
                    }
                    $entry['status'] = $report['dry_run'] ? 'would_create_draft' : 'created_draft';
                    if ($report['dry_run']) {
                        continue;
                    }
                    $artwork = $this->usableArtwork($row, $evidence[$entry['row']] ?? null) ? $row['artwork_url'] : null;
                    $owner = $season ? SeriesEpisode::create([
                        'series_id' => $season->series_id, 'season_id' => $season->id,
                        'episode_number' => $entry['relationship']['episode_number'],
                        'title' => $row['title'], 'still_url' => $artwork, 'published' => false,
                    ]) : Movie::create([
                        'title' => $row['title'], 'slug' => 'youtube-'.hash('sha256', $id),
                        'poster_url' => $artwork, 'published' => false, 'featured' => false,
                    ]);
                    $source = $owner->videoSources()->create([
                        'label' => 'youtube', 'kind' => 'youtube', 'url' => YouTubeUrl::canonicalUrl($row['youtube_url']),
                        'is_default' => true, 'enabled' => true,
                    ]);
                    // Existing schema stores immutable provenance in its indexed
                    // polymorphic audit trail. Bind it to the owner and video ID,
                    // so CMS source replacement cannot misattribute another video.
                    $metadata = $row;
                    unset($metadata['youtube_url']);
                    AdminAuditLog::record(null, 'catalog.youtube.imported', $owner, 'YouTube draft catalog import', [
                        'batch' => $batch, 'source_sha256' => $sha, 'row' => $entry['row'],
                        'video_id' => $id, 'metadata' => $metadata,
                        'channel_name' => $evidence[$entry['row']]['oembed']['author_name'] ?? YouTubeCatalog::CHANNELS[strtolower(rtrim($row['channel_url'], '/'))],
                        'verified_at' => $report['verification_checked_at'],
                        'relationship' => $entry['relationship'] ?? null,
                    ]);
                    $entry['created'] = ['owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->id, 'source_id' => $source->id];
                    $existing[$id] = [$source->id];
                }
                unset($entry);
            };
            $verificationPath = (string) $this->option('verification-report');
            $report['verification_checked_at'] = $verificationPath !== '' ? $this->json($verificationPath)['checked_at'] : null;
            if ($report['dry_run']) {
                $operation(); // Real dry-run: SELECTs only, even on PostgreSQL sequences.
            } else {
                DB::transaction($operation);
            }
            $report['success'] = true;
        } catch (Throwable $exception) {
            $report['success'] = false;
            $report['error'] = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'Import failed; transaction rolled back ('.get_class($exception).').';
            foreach ($report['rows'] as &$entry) {
                if (($entry['status'] ?? null) === 'created_draft') {
                    $entry['status'] = 'rolled_back';
                    unset($entry['created']);
                }
            }
            unset($entry);
        }
        $report['summary'] = [
            'total' => count($report['rows']),
            'classifications' => collect($report['rows'])->countBy('metadata.video_kind')->all(),
            'validation' => collect($report['rows'])->countBy('validation')->all(),
            'verification' => collect($report['rows'])->countBy('verification')->all(),
            'dispositions' => collect($report['rows'])->countBy('status')->all(),
        ];
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        if (is_resource($output)) {
            fwrite($output, $json);
            fclose($output);
        }
        $this->output->write($json);

        return $report['success'] ? self::SUCCESS : self::FAILURE;
    }

    private function json(string $path): array
    {
        if (! is_file($path) || ! is_readable($path) || filesize($path) > 4 * 1024 * 1024) {
            throw new InvalidArgumentException('JSON input must be readable and no larger than 4 MiB.');
        }
        $data = json_decode(file_get_contents($path), true);
        if (! is_array($data)) {
            throw new InvalidArgumentException('Invalid JSON input.');
        }

        return $data;
    }

    /** @return array<int, array<string, mixed>> */
    private function evidence(string $sha): array
    {
        if (! $this->option('verification-report')) {
            return [];
        }
        $data = $this->json((string) $this->option('verification-report'));
        $checked = strtotime($data['checked_at'] ?? '');
        if (($data['source_sha256'] ?? null) !== $sha || $checked === false || $checked > time() + 300 || $checked < time() - 30 * 86400 || ! is_array($data['rows'] ?? null)) {
            throw new InvalidArgumentException('Verification must match CSV SHA-256 and be no older than 30 days.');
        }
        $result = [];
        foreach ($data['rows'] as $row) {
            if (! is_array($row) || ! is_int($row['row'] ?? null) || isset($result[$row['row']])) {
                throw new InvalidArgumentException('Verification row identifiers must be unique integers.');
            }
            $result[$row['row']] = $row;
        }

        return $result;
    }

    private function usableArtwork(array $row, ?array $evidence): bool
    {
        return $row['artwork_url'] !== '' && ($evidence['artwork_http'] ?? null) === 200
            && str_starts_with($evidence['artwork_content_type'] ?? '', 'image/');
    }
}
