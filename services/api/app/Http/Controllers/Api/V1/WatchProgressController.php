<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWatchProgressRequest;
use App\Http\Resources\WatchProgressResource;
use App\Models\WatchProgress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WatchProgressController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'movie_id' => ['sometimes', 'integer', 'min:1'],
            'episode_id' => ['sometimes', 'integer', 'min:1'],
        ]);

        if (isset($filters['movie_id'], $filters['episode_id'])) {
            abort(422, 'Filter by either a movie or an episode, not both.');
        }

        $rows = WatchProgress::query()
            ->where('user_id', $request->user()->id)
            // Rows whose content was unpublished afterwards never leak through
            // the progress list, for movies and the full episode hierarchy.
            ->where(function ($query) {
                $query->whereHas('movie', fn ($movies) => $movies->published())
                    ->orWhereHas('episode', fn ($episodes) => $episodes
                        ->where('series_episodes.published', true)
                        ->whereHas('season', fn ($seasons) => $seasons->where('seasons.published', true))
                        ->whereHas('series', fn ($series) => $series->where('series.published', true)));
            })
            ->when(isset($filters['movie_id']), fn ($query) => $query->where('movie_id', $filters['movie_id']))
            ->when(isset($filters['episode_id']), fn ($query) => $query->where('episode_id', $filters['episode_id']))
            ->with(['movie.genres', 'movie.videoSources', 'episode.season', 'episode.series'])
            ->latest('last_watched_at')
            ->paginate((int) config('ott.catalog.page_size'))
            ->withQueryString();

        return WatchProgressResource::collection($rows);
    }

    public function store(StoreWatchProgressRequest $request): WatchProgressResource
    {
        $data = $request->validated();

        $isEpisode = filled($data['episode_id'] ?? null);

        $progress = WatchProgress::updateOrCreate(
            $isEpisode
                ? ['user_id' => $request->user()->id, 'episode_id' => (int) $data['episode_id']]
                : ['user_id' => $request->user()->id, 'movie_id' => (int) $data['movie_id']],
            [
                'position_seconds' => (int) $data['position_seconds'],
                'duration_seconds' => isset($data['duration_seconds']) ? (int) $data['duration_seconds'] : null,
                'completed' => (bool) ($data['completed'] ?? false),
                'last_watched_at' => now(),
            ],
        );

        return WatchProgressResource::make($progress->load([
            'movie.genres',
            'movie.videoSources',
            'episode.season',
            'episode.series',
        ]));
    }
}
