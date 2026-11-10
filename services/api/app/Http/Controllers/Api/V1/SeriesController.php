<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EpisodeMapper;
use App\Http\Resources\SeriesDetailResource;
use App\Http\Resources\SeriesResource;
use App\Models\Season;
use App\Models\Series;
use App\Models\SeriesEpisode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class SeriesController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'category' => ['sometimes', 'nullable', 'string', 'max:60'],
            'featured' => ['sometimes', 'boolean'],
            'q' => ['sometimes', 'string', 'min:2', 'max:120'],
            'sort' => ['sometimes', 'in:latest,title,featured'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.config('ott.catalog.max_page_size')],
        ]);

        $series = Series::query()
            ->published()
            ->with('genres')
            ->withCount(['episodes' => fn ($query) => $query
                ->where('series_episodes.published', true)
                ->whereHas('season', fn ($season) => $season->where('seasons.published', true)),
            ])
            ->withCount(['seasons' => fn ($query) => $query->where('published', true)])
            ->category($filters['category'] ?? null)
            ->search($filters['q'] ?? null)
            ->when(isset($filters['featured']), fn ($query) => $query->where('featured', (bool) $filters['featured']));

        match ($filters['sort'] ?? 'latest') {
            'title' => $series->orderBy('title'),
            'featured' => $series->orderByDesc('featured')->orderByDesc('published_at'),
            default => $series->orderByDesc('published_at'),
        };

        $series->orderByDesc('id');

        return SeriesResource::collection(
            $series->paginate((int) ($filters['per_page'] ?? config('ott.catalog.page_size')))->withQueryString()
        );
    }

    public function show(Request $request, Series $series): SeriesDetailResource
    {
        // Same enumeration guard as movies: unpublished series 404 for everyone
        // but administrators; the route has no auth middleware, so the sanctum
        // guard must be named explicitly.
        $isAdmin = $request->user('sanctum')?->isAdmin() === true;
        abort_unless($series->published || $isAdmin, 404);

        $series->load('genres');

        if ($isAdmin) {
            // Draft preview: every season and episode, catalog-ordered. The
            // seasons() relation already orders specials-last, and each
            // season's episodes are episode_number-ordered, so the flat
            // compatibility list is just the concatenation.
            $series->load(['seasons.episodes.videoSources', 'creator']);
            $series->setRelation('episodes', $series->seasons
                ->flatMap(fn (Season $season) => $season->episodes
                    ->each(fn (SeriesEpisode $episode) => $episode->setRelation('season', $season)))
                ->values());
        } else {
            $series->load(['publishedSeasons' => fn ($query) => $query
                ->with(['episodes' => fn ($episodes) => $episodes
                    ->where('published', true)
                    ->with('videoSources')
                    ->orderBy('episode_number'),
                ]),
            ]);
            $series->publishedSeasons->each(fn (Season $season) => $season->episodes
                ->each(fn (SeriesEpisode $episode) => $episode->setRelation('season', $season)));
            $series->setRelation('seasons', $series->publishedSeasons);
            $series->setRelation('episodes', $series->catalogEpisodes()->with('videoSources')->get());
        }

        return new SeriesDetailResource($series);
    }

    /**
     * GET /series/{series:slug}/seasons — published season list (admins see all).
     */
    public function seasons(Request $request, Series $series): AnonymousResourceCollection
    {
        $isAdmin = $request->user('sanctum')?->isAdmin() === true;
        abort_unless($series->published || $isAdmin, 404);

        $seasons = $isAdmin
            ? $series->seasons()->withCount('episodes')->get()
            : $series->publishedSeasons()
                ->withCount(['episodes' => fn ($query) => $query->where('published', true)])
                ->get();

        return JsonResource::collection($seasons->map(fn (Season $season) => [
            'id' => $season->id,
            'season_number' => $season->season_number,
            'title' => $season->title,
            'synopsis' => $season->synopsis,
            'poster_url' => $season->poster_url,
            'backdrop_url' => $season->backdrop_url,
            'published' => $isAdmin ? $season->published : null,
            'episode_count' => $season->episodes_count,
        ]));
    }

    /**
     * GET /series/{series:slug}/seasons/{seasonNumber}/episodes
     *
     * The season is addressed by its number within the series, so a season
     * belonging to a different series cannot be reached through this route
     * (mismatched nested identifiers 404).
     */
    public function episodes(Request $request, Series $series, int $seasonNumber): AnonymousResourceCollection
    {
        $isAdmin = $request->user('sanctum')?->isAdmin() === true;
        abort_unless($series->published || $isAdmin, 404);

        $season = $series->seasons()->where('season_number', $seasonNumber)->first();
        abort_if($season === null || (! $season->published && ! $isAdmin), 404);

        $episodes = $isAdmin
            ? $season->episodes()->with('videoSources')->get()
            : $season->publishedEpisodes()->with('videoSources')->get();

        return JsonResource::collection($episodes->map(fn (SeriesEpisode $episode) => array_merge(
            EpisodeMapper::publicEpisode($episode->setRelation('season', $season)),
            ['published' => $isAdmin ? $episode->published : null],
        )));
    }
}
