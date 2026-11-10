<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWatchlistRequest;
use App\Http\Resources\WatchlistResource;
use App\Models\Movie;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WatchlistController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $rows = $request->user()
            ->watchlist()
            ->whereHas('movie', fn ($query) => $query->published())
            ->with(['movie.genres', 'movie.videoSources'])
            ->latest()
            ->paginate((int) config('ott.catalog.page_size'))
            ->withQueryString();

        return WatchlistResource::collection($rows);
    }

    public function store(StoreWatchlistRequest $request): JsonResponse
    {
        $row = $request->user()->watchlist()->firstOrCreate([
            'movie_id' => $request->integer('movie_id'),
        ]);

        $row->load(['movie.genres', 'movie.videoSources']);

        return WatchlistResource::make($row)->response()
            ->setStatusCode($row->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, Movie $movie): JsonResponse
    {
        $removed = $request->user()->watchlist()->where('movie_id', $movie->id)->delete();

        abort_unless($removed > 0, 404);

        return response()->json(['status' => 'removed']);
    }
}
