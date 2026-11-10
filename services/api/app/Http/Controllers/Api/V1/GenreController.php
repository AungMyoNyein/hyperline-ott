<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\GenreResource;
use App\Models\Genre;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GenreController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $genres = Genre::query()
            ->withCount(['movies' => fn ($query) => $query->where('movies.published', true)])
            ->orderBy('name')
            ->get();

        return GenreResource::collection($genres);
    }
}
