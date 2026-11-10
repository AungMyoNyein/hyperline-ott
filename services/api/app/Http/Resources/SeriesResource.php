<?php

namespace App\Http\Resources;

use App\Models\Series;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public series card shape. Like MovieResource it never contains stream
 * locations; episode playback origins are only released through authorised
 * playback sessions.
 *
 * @mixin Series
 */
class SeriesResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'series',
            'title' => $this->title,
            'slug' => $this->slug,
            'synopsis' => $this->synopsis,
            'category' => $this->category,
            'language' => $this->language,
            'release_year' => $this->release_year,
            'poster_url' => $this->poster_url,
            'backdrop_url' => $this->backdrop_url,
            'featured' => $this->featured,
            'published_at' => $this->published_at,
            'episode_count' => $this->whenCounted('episodes'),
            'season_count' => $this->whenCounted('seasons'),
            'genres' => GenreResource::collection($this->whenLoaded('genres')),
        ];
    }
}
