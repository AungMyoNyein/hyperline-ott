<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MovieResource;
use App\Http\Resources\SeriesResource;
use App\Models\Movie;
use App\Models\Series;
use App\Models\WatchProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $catalog = Movie::query()->published()->with(['genres', 'videoSources']);

        $hero = (clone $catalog)->featured()->latest('published_at')->first();
        $featured = (clone $catalog)->featured()->latest('published_at')->take(10)->get();
        $trending = $this->trending($request);
        $latest = (clone $catalog)->latest('published_at')->take(12)->get();

        return response()->json([
            'data' => [
                'hero' => $hero instanceof Movie ? $this->row($hero, $request) : null,
                'featured' => $this->rows($featured, $request),
                'trending' => $this->rows($trending, $request),
                'latest' => $this->rows($latest, $request),
                'series' => SeriesResource::collection(
                    Series::query()
                        ->published()
                        ->withCount(['episodes' => fn ($query) => $query
                            ->where('series_episodes.published', true)
                            ->whereHas('season', fn ($seasons) => $seasons->where('seasons.published', true)),
                        ])
                        ->withCount(['seasons' => fn ($query) => $query->where('published', true)])
                        ->orderByDesc('featured')
                        ->orderByDesc('published_at')
                        ->take(12)
                        ->get()
                )->toArray($request),
                'continue_watching' => $this->continueWatching($request),
            ],
        ]);
    }

    private function trending(Request $request)
    {
        return Movie::query()
            ->published()
            ->with(['genres', 'videoSources'])
            ->withCount(['playbackSessions' => fn ($query) => $query->where('started_at', '>=', now()->subDays(30))])
            ->orderByDesc('playback_sessions_count')
            ->orderByDesc('published_at')
            ->take(12)
            ->get();
    }

    private function continueWatching(Request $request): array
    {
        // /home is public, so there is no auth middleware to populate the default
        // guard; name sanctum explicitly to pick up an optional bearer token.
        $user = $request->user('sanctum');

        if ($user === null) {
            return [];
        }

        $rows = WatchProgress::query()
            ->where('user_id', $user->id)
            ->where('completed', false)
            ->where(function ($query) {
                $query->whereHas('movie', fn ($movies) => $movies->where('published', true))
                    ->orWhereHas('episode', fn ($episodes) => $episodes
                        ->where('series_episodes.published', true)
                        ->whereHas('season', fn ($seasons) => $seasons->where('seasons.published', true))
                        ->whereHas('series', fn ($series) => $series->where('series.published', true)));
            })
            ->with(['movie.genres', 'movie.videoSources', 'episode.season', 'episode.series'])
            ->latest('last_watched_at')
            ->take(12)
            ->get();

        return $rows->map(fn (WatchProgress $progress) => $progress->episode_id !== null
            ? $this->episodeRow($progress)
            : array_merge(
                $this->row($progress->movie, $request),
                [
                    'position_seconds' => $progress->position_seconds,
                    'duration_seconds' => $progress->duration_seconds,
                    'last_watched_at' => $progress->last_watched_at?->toIso8601String(),
                ],
            ))->all();
    }

    /**
     * Episode continue-watching rows are additive: movie rows keep their exact
     * Phase 1 shape (MovieResource merged with progress fields), while episode
     * rows are discriminated by `type` and carry series context so the web app
     * can build the watch link without a second request.
     *
     * @return array<string, mixed>
     */
    private function episodeRow(WatchProgress $progress): array
    {
        $episode = $progress->episode;

        return [
            'type' => 'episode',
            'id' => $episode->id,
            'episode' => [
                'id' => $episode->id,
                'episode_number' => $episode->episode_number,
                'season_number' => $episode->season?->season_number,
                'title' => $episode->title,
                'duration_seconds' => $episode->duration_seconds,
                'still_url' => $episode->still_url,
            ],
            'series' => [
                'id' => $episode->series?->id,
                'slug' => $episode->series?->slug,
                'title' => $episode->series?->title,
                'poster_url' => $episode->series?->poster_url,
            ],
            'position_seconds' => $progress->position_seconds,
            'duration_seconds' => $progress->duration_seconds,
            'last_watched_at' => $progress->last_watched_at?->toIso8601String(),
        ];
    }

    private function row(Movie $movie, Request $request): array
    {
        return (new MovieResource($movie))->toArray($request);
    }

    private function rows($movies, Request $request): array
    {
        return $movies->map(fn (Movie $movie) => $this->row($movie, $request))->all();
    }
}
