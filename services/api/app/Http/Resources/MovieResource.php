<?php

namespace App\Http\Resources;

use App\Models\Movie;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public catalog shape. It never contains stream locations: playback origin URLs
 * are only released through an authorised playback session.
 *
 * @mixin Movie
 */
class MovieResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'movie',
            'title' => $this->title,
            'slug' => $this->slug,
            'synopsis' => $this->synopsis,
            'release_year' => $this->release_year,
            'duration_minutes' => $this->duration_minutes,
            'poster_url' => $this->poster_url,
            'backdrop_url' => $this->backdrop_url,
            'featured' => $this->featured,
            'published_at' => $this->published_at,
            'genres' => GenreResource::collection($this->whenLoaded('genres')),
            'playable' => $this->hasPlayableSource(),
        ];
    }
}
