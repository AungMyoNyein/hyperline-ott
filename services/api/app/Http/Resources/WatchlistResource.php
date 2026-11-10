<?php

namespace App\Http\Resources;

use App\Models\Watchlist;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Watchlist
 */
class WatchlistResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'added_at' => $this->created_at,
            'movie' => new MovieResource($this->whenLoaded('movie')),
        ];
    }
}
