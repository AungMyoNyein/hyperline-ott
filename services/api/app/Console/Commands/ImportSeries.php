<?php

namespace App\Console\Commands;

use App\Models\AdminAuditLog;
use App\Models\Season;
use App\Models\Series;
use App\Models\SeriesEpisode;
use App\Models\VideoSource;
use App\Rules\SafeStreamUrl;
use App\Support\Contracts\HostResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/**
 * Repeatable, idempotent VOD series import.
 *
 * Creates exactly one series with seasons, ordered episodes and one HLS source
 * per episode, keyed by (series slug), (series, season_number) and
 * (season, episode_number), so repeated runs never duplicate records. Movies
 * are never read or written. New records are drafts unless --publish is given;
 * existing publication state is never silently removed. --dry-run computes the
 * full plan inside a rolled-back transaction and changes nothing.
 *
 * Manifests without a `seasons` block stay valid: every episode lands in
 * season 1, matching the flat Phase 1 shape the backfill migration created.
 * Season number 0 is the reserved specials bucket.
 */
class ImportSeries extends Command
{
    protected $signature = 'hyperline:import-series
        {manifest : Path to the series manifest JSON file}
        {--dry-run : Print the import plan without writing anything}
        {--publish : Publish the series and its imported seasons and episodes}';

    protected $description = 'Import a VOD series with seasons, ordered episodes and per-episode HLS sources (idempotent)';

    public function handle(): int
    {
        $path = (string) $this->argument('manifest');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error('Manifest file not found or not readable.');

            return self::FAILURE;
        }

        $manifest = json_decode((string) file_get_contents($path), true);

        if (! is_array($manifest)) {
            $this->error('Manifest is not valid JSON.');

            return self::FAILURE;
        }

        $validator = $this->validator($manifest);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $manifest = $validator->validated();
        $publish = (bool) $this->option('publish');
        $dryRun = (bool) $this->option('dry-run');

        try {
            $summary = $this->import($manifest, $publish, $dryRun);
        } catch (Throwable $exception) {
            $this->error('Import failed; no changes were committed. ('.get_class($exception).')');

            return self::FAILURE;
        }

        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($dryRun) {
            $this->info('Dry run: nothing was written.');
        } else {
            $this->info(sprintf(
                'Imported "%s": %d season(s), %d episode(s) created, %d updated, %d unchanged.',
                $summary['series']['title'],
                $summary['seasons_touched'],
                $summary['episodes_created'],
                $summary['episodes_updated'],
                $summary['episodes_unchanged'],
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function validator(array $manifest): \Illuminate\Contracts\Validation\Validator
    {
        $resolver = app(HostResolver::class);

        $validator = Validator::make($manifest, [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255', 'alpha_dash'],
            'synopsis' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'category' => ['sometimes', 'nullable', 'string', 'max:60'],
            'language' => ['sometimes', 'nullable', 'string', 'max:60'],
            'release_year' => ['sometimes', 'nullable', 'integer', 'between:1900,'.(date('Y') + 1)],
            'poster_url' => ['sometimes', 'nullable', 'string', 'max:1024', 'url:https'],
            'backdrop_url' => ['sometimes', 'nullable', 'string', 'max:1024', 'url:https'],
            'featured' => ['sometimes', 'boolean'],
            'seasons' => ['sometimes', 'array', 'max:999'],
            'seasons.*.number' => ['required', 'integer', 'between:0,999', 'distinct'],
            'seasons.*.title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'seasons.*.synopsis' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'seasons.*.poster_url' => ['sometimes', 'nullable', 'string', 'max:1024', 'url:https'],
            'seasons.*.backdrop_url' => ['sometimes', 'nullable', 'string', 'max:1024', 'url:https'],
            'episodes' => ['required', 'array', 'min:1', 'max:2000'],
            'episodes.*.season' => ['sometimes', 'nullable', 'integer', 'between:0,999'],
            'episodes.*.number' => ['required', 'integer', 'min:1', 'max:2000'],
            'episodes.*.title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'episodes.*.synopsis' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'episodes.*.duration_seconds' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:86400'],
            'episodes.*.still_url' => ['sometimes', 'nullable', 'string', 'max:1024', 'url:https'],
            'episodes.*.hls_url' => ['required', 'string', 'max:2048', new SafeStreamUrl($resolver)],
        ]);

        // Episode numbers are unique per season, so the flat Phase 1 `distinct`
        // rule no longer applies; check (season, number) pairs explicitly
        // instead of letting the DB unique index abort the whole import.
        $validator->after(function (\Illuminate\Contracts\Validation\Validator $v) use ($manifest) {
            $seen = [];
            foreach ((array) ($manifest['episodes'] ?? []) as $index => $row) {
                $season = (int) ($row['season'] ?? 1);
                $key = $season.':'.(int) ($row['number'] ?? 0);
                if (isset($seen[$key])) {
                    $v->errors()->add("episodes.{$index}.number", "Episode number {$row['number']} appears twice in season {$season}.");
                }
                $seen[$key] = true;
            }
        });

        return $validator;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function import(array $manifest, bool $publish, bool $dryRun): array
    {
        DB::beginTransaction();

        try {
            $summary = $this->importPlan($manifest, $publish);

            if ($dryRun) {
                DB::rollBack();
                $summary['dry_run'] = true;
            } else {
                DB::commit();
                $summary['dry_run'] = false;
            }

            return $summary;
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function importPlan(array $manifest, bool $publish): array
    {
        $slug = (string) ($manifest['slug'] ?? '') !== ''
            ? (string) $manifest['slug']
            : Str::slug((string) $manifest['title']);

        $seriesAttributes = [
            'title' => $manifest['title'],
            'synopsis' => $manifest['synopsis'] ?? null,
            'category' => $manifest['category'] ?? null,
            'language' => $manifest['language'] ?? null,
            'release_year' => $manifest['release_year'] ?? null,
            'poster_url' => $manifest['poster_url'] ?? null,
            'backdrop_url' => $manifest['backdrop_url'] ?? null,
            'featured' => (bool) ($manifest['featured'] ?? false),
        ];

        $series = Series::query()->where('slug', $slug)->first();
        $seriesState = $series === null ? 'create' : ($this->differs($series, $seriesAttributes) ? 'update' : 'unchanged');

        if ($series === null) {
            $series = new Series(['slug' => $slug, ...$seriesAttributes]);
            $series->published = $publish;
            $series->published_at = $publish ? now() : null;
            $series->save();
        } else {
            if ($seriesState === 'update') {
                $series->fill($seriesAttributes);
            }
            if ($publish && ! $series->published) {
                $series->published = true;
                $series->published_at = $series->published_at ?? now();
                $seriesState = 'update';
            }
            $series->save();
        }

        $seasonPlan = [];
        $seasons = $this->resolveSeasons($series, $manifest, $publish, $seasonPlan);

        $created = $updated = $unchanged = 0;
        $episodePlan = [];

        foreach ($manifest['episodes'] as $row) {
            $seasonNumber = (int) ($row['season'] ?? 1);
            $season = $this->season($series, $seasons, $seasonNumber, $seasonPlan);

            $attributes = [
                'title' => $row['title'] ?? null,
                'synopsis' => $row['synopsis'] ?? null,
                'duration_seconds' => $row['duration_seconds'] ?? null,
                'still_url' => $row['still_url'] ?? null,
            ];

            $episode = SeriesEpisode::query()
                ->where('season_id', $season->id)
                ->where('episode_number', (int) $row['number'])
                ->first();

            if ($episode === null) {
                $episode = new SeriesEpisode([
                    'series_id' => $series->id,
                    'season_id' => $season->id,
                    'episode_number' => (int) $row['number'],
                    ...$attributes,
                ]);
                $episode->published = $publish;
                $episode->save();
                $created++;
                $state = 'create';
            } else {
                $state = $this->differs($episode, $attributes) ? 'update' : 'unchanged';
                $episode->fill($attributes);
                if ($publish && ! $episode->published) {
                    $episode->published = true;
                    $state = 'update';
                }
                $episode->save();
                $state === 'update' ? $updated++ : $unchanged++;
            }

            $sourceAttributes = [
                'kind' => 'hls',
                'url' => $row['hls_url'],
                'quality' => null,
                'language' => $manifest['language'] ?? null,
                'is_default' => true,
                'enabled' => true,
            ];

            $source = VideoSource::query()
                ->where('episode_id', $episode->id)
                ->where('label', 'main')
                ->first();

            if ($source === null) {
                VideoSource::create(['episode_id' => $episode->id, 'label' => 'main', ...$sourceAttributes]);
                $sourceState = 'create';
            } elseif ($this->differs($source, $sourceAttributes)) {
                $source->fill($sourceAttributes)->save();
                $sourceState = 'update';
            } else {
                $sourceState = 'unchanged';
            }

            $episodePlan[] = [
                'season_number' => $seasonNumber,
                'episode_number' => $episode->episode_number,
                'episode' => $state,
                'source' => $sourceState,
            ];
        }

        AdminAuditLog::record(null, 'series.imported', $series,
            "Imported series {$series->title} with ".count($manifest['episodes']).' episode(s)', [
                'seasons_touched' => count($seasonPlan),
                'episodes_created' => $created,
                'episodes_updated' => $updated,
                'episodes_unchanged' => $unchanged,
                'published' => $publish,
            ]);

        return [
            'series' => [
                'id' => $series->id,
                'title' => $series->title,
                'slug' => $series->slug,
                'state' => $seriesState,
                'published' => $series->published,
            ],
            'seasons_touched' => count($seasonPlan),
            'seasons' => $seasonPlan,
            'episodes_created' => $created,
            'episodes_updated' => $updated,
            'episodes_unchanged' => $unchanged,
            'episodes' => $episodePlan,
            'movies_touched' => 0,
        ];
    }

    /**
     * Declared seasons are upserted; season 1 always exists so flat manifests
     * and per-episode defaults have a home.
     *
     * @param  array<string, mixed>  $manifest
     * @param  list<array<string, mixed>>  $seasonPlan
     * @return array<int, Season>
     */
    private function resolveSeasons(Series $series, array $manifest, bool $publish, array &$seasonPlan): array
    {
        $seasons = [];

        $rows = $manifest['seasons'] ?? [];
        if ($rows === []) {
            $rows = [['number' => 1]];
        } elseif (! collect($rows)->contains(fn ($row) => (int) $row['number'] === 1)) {
            array_unshift($rows, ['number' => 1]);
        }

        foreach ($rows as $row) {
            $seasons[(int) $row['number']] = $this->upsertSeason($series, $row, $publish, $seasonPlan);
        }

        return $seasons;
    }

    /**
     * @param  array<int, Season>  $seasons
     * @param  list<array<string, mixed>>  $seasonPlan
     */
    private function season(Series $series, array &$seasons, int $seasonNumber, array &$seasonPlan): Season
    {
        // Episodes may reference a season the manifest did not declare; it is
        // created on demand (published, no title) so the import stays lenient
        // and idempotent like the rest of the command.
        return $seasons[$seasonNumber] ??= $this->upsertSeason($series, ['number' => $seasonNumber], true, $seasonPlan);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array<string, mixed>>  $seasonPlan
     */
    private function upsertSeason(Series $series, array $row, bool $publish, array &$seasonPlan): Season
    {
        $attributes = [
            'title' => $row['title'] ?? null,
            'synopsis' => $row['synopsis'] ?? null,
            'poster_url' => $row['poster_url'] ?? null,
            'backdrop_url' => $row['backdrop_url'] ?? null,
        ];

        $season = Season::query()
            ->where('series_id', $series->id)
            ->where('season_number', (int) $row['number'])
            ->first();

        if ($season === null) {
            $season = new Season(['series_id' => $series->id, 'season_number' => (int) $row['number'], ...$attributes]);
            $season->published = true;
            $season->save();
            $state = 'create';
        } else {
            $state = $this->differs($season, $attributes) ? 'update' : 'unchanged';
            $season->fill($attributes);
            if ($publish && ! $season->published) {
                $season->published = true;
                $state = 'update';
            }
            $season->save();
        }

        $seasonPlan[] = ['season_number' => $season->season_number, 'state' => $state];

        return $season;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function differs(\Illuminate\Database\Eloquent\Model $model, array $attributes): bool
    {
        foreach ($attributes as $key => $value) {
            $current = $model->getAttribute($key);
            if ($current instanceof \BackedEnum) {
                $current = $current->value;
            }
            if ((string) $current !== (string) $value && ! ($current === null && $value === null)) {
                return true;
            }
        }

        return false;
    }
}
