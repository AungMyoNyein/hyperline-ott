<?php

namespace App\Http\Resources;

use App\Models\Genre;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Genre
 */
class GenreResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'name_en' => $this->name,
            'name_my' => $this->name_my,
            'enabled' => $this->enabled,
            'sort_order' => $this->sort_order,
            'series_count' => $this->whenCounted('series'),
            'movies_count' => $this->whenCounted('movies'),
        ];
    }
}
