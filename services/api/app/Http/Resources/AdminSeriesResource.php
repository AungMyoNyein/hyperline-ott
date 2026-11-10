<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * CMS-only shape: the single place episode stream locations are serialised,
 * mirroring AdminMovieResource. The route group is limited to administrators.
 *
 * Compatibility: the flat `episodes` key stays for the existing CMS series
 * library; `seasons` (with nested episodes) is additive.
 */
class AdminSeriesResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'synopsis' => $this->synopsis,
            'category' => $this->category,
            'language' => $this->language,
            'release_year' => $this->release_year,
            'poster_url' => $this->poster_url,
            'backdrop_url' => $this->backdrop_url,
            'featured' => $this->featured,
            'published' => $this->published,
            'published_at' => $this->published_at,
            'created_by' => $this->whenLoaded('creator', fn () => [
                'id' => $this->creator?->id,
                'name' => $this->creator?->name,
                'email' => $this->creator?->email,
            ]),
            'episode_count' => $this->whenCounted('episodes'),
            'season_count' => $this->whenCounted('seasons'),
            'genres' => GenreResource::collection($this->whenLoaded('genres')),
            'seasons' => $this->whenLoaded('seasons', fn () => $this->seasons
                ->map(EpisodeMapper::adminSeason(...))
                ->values()
                ->all()),
            'episodes' => $this->whenLoaded('episodes', fn () => $this->episodes
                ->map(EpisodeMapper::adminEpisode(...))
                ->values()
                ->all()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
