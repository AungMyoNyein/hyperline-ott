<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSeriesRequest;
use App\Http\Requests\UpdateSeriesRequest;
use App\Http\Resources\AdminSeriesResource;
use App\Models\AdminAuditLog;
use App\Models\Genre;
use App\Models\Season;
use App\Models\Series;
use App\Models\SeriesEpisode;
use App\Models\VideoSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Series administration: full CRUD for the series container plus metadata and
 * publication toggles. Seasons and episodes are administered through the
 * nested Admin\SeasonController / Admin\EpisodeController. Bulk creation still
 * also works through the reviewed `hyperline:import-series` command. Source
 * URLs are validated with the same SSRF-guarded rules movies use.
 */
class SeriesController extends Controller
{
    public function index(Request $request): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $filters = $request->validate([
            'published' => ['sometimes', 'boolean'],
            'category' => ['sometimes', 'string', 'max:60'],
            'q' => ['sometimes', 'string', 'min:2', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.config('ott.catalog.max_page_size')],
        ]);

        $series = Series::query()
            ->withCount('episodes')
            ->withCount('seasons')
            ->category($filters['category'] ?? null)
            ->search($filters['q'] ?? null)
            ->when(isset($filters['published']), fn ($query) => $query->where('published', (bool) $filters['published']))
            ->latest()
            ->paginate((int) ($filters['per_page'] ?? 50))
            ->withQueryString();

        return AdminSeriesResource::collection($series);
    }

    public function show(Series $series): AdminSeriesResource
    {
        return $this->present($series);
    }

    public function store(StoreSeriesRequest $request): JsonResponse
    {
        $data = $request->validated();
        $published = (bool) ($data['published'] ?? false);

        return DB::transaction(function () use ($request, $data, $published): JsonResponse {
            $series = Series::create([
                ...collect($data)->except(['genres', 'published'])->all(),
                'published' => $published,
                'published_at' => $published ? now() : null,
                'created_by' => $request->user()->id,
            ]);

            $this->syncGenres($series, $data['genres'] ?? null);

            // Every series starts with its season 1 so episodes always have a
            // home; the seasons backfill migration established the same shape.
            $series->seasons()->create(['season_number' => 1, 'published' => true]);

            AdminAuditLog::record($request->user(), 'series.created', $series, "Created series {$series->title}", [
                'after' => $this->snapshot($series),
            ]);

            return AdminSeriesResource::make($this->load($series)->loadCount(['seasons', 'episodes']))
                ->response()
                ->setStatusCode(201);
        });
    }

    public function update(UpdateSeriesRequest $request, Series $series): AdminSeriesResource
    {
        $data = $request->validated();

        return DB::transaction(function () use ($request, $series, $data): AdminSeriesResource {
            $before = $this->snapshot($this->load($series));

            if (array_key_exists('published', $data)) {
                $data['published_at'] = $data['published'] ? ($series->published_at ?? now()) : $series->published_at;
            }

            $series->update(collect($data)->except('genres')->all());
            if (array_key_exists('genres', $data)) {
                $this->syncGenres($series, $data['genres']);
            }

            $this->load($series);

            AdminAuditLog::record($request->user(), 'series.updated', $series, "Updated series {$series->title}", [
                'changed' => array_keys($series->getChanges()),
                'before' => $before,
                'after' => $this->snapshot($series),
            ]);

            return new AdminSeriesResource($series);
        });
    }

    public function destroy(Request $request, Series $series): JsonResponse
    {
        return DB::transaction(function () use ($request, $series): JsonResponse {
            $this->load($series);
            $snapshot = $this->snapshot($series);

            // Cascade is deliberate and documented: series -> seasons ->
            // episodes -> sources/sessions. The CMS requires an explicit
            // typed confirmation before calling this.
            $series->delete();

            AdminAuditLog::record($request->user(), 'series.deleted', $series, "Deleted series {$snapshot['title']}", [
                'before' => $snapshot,
            ]);

            return response()->json(['status' => 'deleted']);
        });
    }

    /**
     * Legacy publication/metadata toggle kept for the existing CMS. Full edits
     * go through the same validation as update(); this route only ever sends
     * partial payloads, which UpdateSeriesRequest already permits.
     */
    public function updateEpisode(Request $request, Series $series, SeriesEpisode $episode): AdminSeriesResource
    {
        abort_unless((int) $episode->series_id === (int) $series->id, 404);

        $data = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'synopsis' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'duration_seconds' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:86400'],
            'still_url' => ['sometimes', 'nullable', 'string', 'max:1024', 'url:https'],
            'published' => ['sometimes', 'boolean'],
        ]);

        return DB::transaction(function () use ($request, $series, $episode, $data): AdminSeriesResource {
            $before = ['published' => $episode->published, 'title' => $episode->title];
            $episode->update($data);

            AdminAuditLog::record(
                $request->user(),
                'series_episode.updated',
                $episode,
                "Updated episode {$episode->episode_number} of {$series->title}",
                ['before' => $before, 'after' => ['published' => $episode->published, 'title' => $episode->title]],
            );

            return $this->present($series);
        });
    }

    private function present(Series $series): AdminSeriesResource
    {
        return new AdminSeriesResource($this->load($series));
    }

    /**
     * Loads the admin payload relations and derives the flat compatibility
     * `episodes` list from the ordered season structure (specials last).
     */
    private function load(Series $series): Series
    {
        $series->load(['seasons.episodes.videoSources', 'genres', 'creator']);
        $series->setRelation('episodes', $series->seasons
            ->flatMap(fn (Season $season) => $season->episodes
                ->each(fn (SeriesEpisode $episode) => $episode->setRelation('season', $season)))
            ->values());

        return $series;
    }

    /**
     * @param  list<string>|null  $slugs
     */
    private function syncGenres(Series $series, ?array $slugs): void
    {
        if ($slugs === null) {
            return;
        }

        $series->genres()->sync(
            Genre::query()->whereIn('slug', $slugs)->pluck('id')->all()
        );
    }

    /**
     * Audit snapshots deliberately omit source URLs, so the trail never becomes
     * a second store of origin locations.
     */
    private function snapshot(Series $series): array
    {
        return [
            'title' => $series->title,
            'slug' => $series->slug,
            'category' => $series->category,
            'language' => $series->language,
            'published' => $series->published,
            'featured' => $series->featured,
            'genres' => $series->relationLoaded('genres') ? $series->genres->pluck('slug')->all() : [],
            'seasons' => $series->relationLoaded('seasons')
                ? $series->seasons
                    ->map(fn (Season $season) => [
                        'number' => $season->season_number,
                        'published' => $season->published,
                        'episodes' => $season->relationLoaded('episodes')
                            ? $season->episodes
                                ->map(fn (SeriesEpisode $episode) => [
                                    'number' => $episode->episode_number,
                                    'published' => $episode->published,
                                    'sources' => $episode->videoSources
                                        ->map(fn (VideoSource $source) => [
                                            'label' => $source->label,
                                            'kind' => $source->kind,
                                            'quality' => $source->quality,
                                            'enabled' => $source->enabled,
                                        ])
                                        ->all(),
                                ])
                                ->all()
                            : [],
                    ])
                    ->all()
                : [],
        ];
    }
}
