<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\GenreResource;
use App\Http\Resources\MovieResource;
use App\Http\Resources\SeriesResource;
use App\Models\Genre;
use App\Models\Movie;
use App\Models\Series;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:120'],
            'genre' => ['sometimes', 'nullable', 'string', 'max:120', 'exists:genres,slug'],
            'limit' => ['sometimes', 'integer', 'between:1,50'],
        ]);

        $limit = (int) ($filters['limit'] ?? 12);

        $movies = Movie::query()
            ->published()
            ->with(['genres', 'videoSources'])
            ->genre($filters['genre'] ?? null)
            ->search($filters['q'])
            ->orderBy('title')
            ->take($limit)
            ->get();

        $needle = '%'.strtolower(trim($filters['q'])).'%';

        $series = Series::query()
            ->published()
            ->withCount(['episodes' => fn ($query) => $query->where('published', true)])
            ->search($filters['q'])
            ->orderBy('title')
            ->take($limit)
            ->get();

        $genres = Genre::query()
            ->withCount(['movies' => fn ($query) => $query->where('movies.published', true)])
            ->where(function ($query) use ($needle) {
                $query->whereRaw('lower(name) like ?', [$needle])
                    ->orWhereRaw('lower(slug) like ?', [$needle]);
            })
            ->orderBy('name')
            ->take(5)
            ->get();

        return response()->json([
            'data' => [
                'query' => $filters['q'],
                // ResourceCollection::toArray() returns the unwrapped item list,
                // so these are assigned directly rather than indexed by 'data'.
                'movies' => MovieResource::collection($movies)->toArray($request),
                'series' => SeriesResource::collection($series)->toArray($request),
                'genres' => GenreResource::collection($genres)->toArray($request),
            ],
        ]);
    }
}
