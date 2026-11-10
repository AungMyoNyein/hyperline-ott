<?php

namespace App\Http\Resources;

use App\Models\WatchProgress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;

/**
 * Compatibility: movie rows keep their Phase 1 shape exactly (`movie` key with
 * MovieResource). `type` is additive on every row; episode rows carry an
 * `episode` summary with its season/series context instead of `movie`.
 *
 * @mixin WatchProgress
 */
class WatchProgressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->episode_id !== null ? 'episode' : 'movie',
            'movie' => $this->movie_id !== null
                ? new MovieResource($this->whenLoaded('movie'))
                : new MissingValue,
            'episode' => $this->episode_id !== null
                ? $this->whenLoaded('episode', fn () => [
                    'id' => $this->episode->id,
                    'episode_number' => $this->episode->episode_number,
                    'season_number' => $this->episode->season?->season_number,
                    'title' => $this->episode->title,
                    'duration_seconds' => $this->episode->duration_seconds,
                    'still_url' => $this->episode->still_url,
                    'series' => [
                        'id' => $this->episode->series?->id,
                        'slug' => $this->episode->series?->slug,
                        'title' => $this->episode->series?->title,
                        'poster_url' => $this->episode->series?->poster_url,
                    ],
                ])
                : new MissingValue,
            'position_seconds' => $this->position_seconds,
            'duration_seconds' => $this->duration_seconds,
            'completed' => $this->completed,
            'last_watched_at' => $this->last_watched_at,
        ];
    }
}
