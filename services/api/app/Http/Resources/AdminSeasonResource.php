<?php

namespace App\Http\Resources;

use App\Models\Season;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin season payload with nested episodes including origin URLs — served
 * only inside the role:admin route group.
 *
 * @mixin Season
 */
class AdminSeasonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return array_merge(EpisodeMapper::adminSeason($this->resource), [
            'series' => $this->whenLoaded('series', fn () => [
                'id' => $this->series->id,
                'title' => $this->series->title,
                'slug' => $this->series->slug,
            ]),
        ]);
    }
}
