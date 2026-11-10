<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MovieDetailResource;
use App\Http\Resources\MovieResource;
use App\Models\Movie;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MovieController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'genre' => ['sometimes', 'nullable', 'string', 'max:120', 'exists:genres,slug'],
            'featured' => ['sometimes', 'boolean'],
            'year' => ['sometimes', 'integer', 'between:1900,'.(date('Y') + 1)],
            'q' => ['sometimes', 'string', 'min:2', 'max:120'],
            'sort' => ['sometimes', 'in:latest,title,year,featured'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.config('ott.catalog.max_page_size')],
        ]);

        $movies = Movie::query()
            ->published()
            ->with(['genres', 'videoSources'])
            ->genre($filters['genre'] ?? null)
            ->search($filters['q'] ?? null)
            ->when(isset($filters['featured']), fn ($query) => $query->where('featured', (bool) $filters['featured']))
            ->when(isset($filters['year']), fn ($query) => $query->where('release_year', (int) $filters['year']));

        match ($filters['sort'] ?? 'latest') {
            'title' => $movies->orderBy('title'),
            'year' => $movies->orderByDesc('release_year')->orderBy('title'),
            'featured' => $movies->orderByDesc('featured')->orderByDesc('published_at'),
            default => $movies->orderByDesc('published_at'),
        };

        $movies->orderByDesc('id');

        return MovieResource::collection(
            $movies->paginate((int) ($filters['per_page'] ?? config('ott.catalog.page_size')))->withQueryString()
        );
    }

    public function show(Request $request, Movie $movie): MovieDetailResource
    {
        // Drafts 404 for everyone but administrators, so unpublished catalog
        // entries are not enumerable through the public detail route. This route
        // has no auth middleware, so the sanctum guard must be named explicitly —
        // the default session guard would never see a bearer token.
        abort_unless($movie->published || $request->user('sanctum')?->isAdmin() === true, 404);

        $movie->load(['genres', 'videoSources']);

        return new MovieDetailResource($movie);
    }
}
