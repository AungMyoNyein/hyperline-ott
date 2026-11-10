<?php

namespace App\Http\Resources;

use App\Models\VideoSource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * CMS-only shape. This is the one place stream locations are serialised, and the
 * route group is limited to administrators.
 */
class AdminMovieResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'synopsis' => $this->synopsis,
            'release_year' => $this->release_year,
            'duration_minutes' => $this->duration_minutes,
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
            'genres' => GenreResource::collection($this->whenLoaded('genres')),
            'sources' => $this->whenLoaded('videoSources', fn () => $this->videoSources
                ->map(fn (VideoSource $source) => [
                    'id' => $source->id,
                    'label' => $source->label,
                    'kind' => $source->kind,
                    'url' => $source->url,
                    'quality' => $source->quality,
                    'language' => $source->language,
                    'is_default' => $source->is_default,
                    'enabled' => $source->enabled,
                ])
                ->values()
                ->all()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
