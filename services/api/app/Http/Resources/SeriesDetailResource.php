<?php

namespace App\Http\Resources;

use App\Models\Season;
use Illuminate\Http\Request;

/**
 * Series detail adds the season structure and the ordered episode list with
 * source *metadata* only. Labels and qualities let the player build a track
 * list; origin URLs come exclusively from playback sessions, mirroring
 * MovieDetailResource.
 *
 * Compatibility: the flat `episodes` key stays (ordered by the catalog rule:
 * main seasons ascending, specials last, episode number ascending) and gains
 * `season_id`/`season_number` per episode; `seasons` is additive.
 */
class SeriesDetailResource extends SeriesResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'seasons' => $this->whenLoaded('seasons', fn () => $this->seasons
                ->map(fn (Season $season) => EpisodeMapper::publicSeason(
                    $season,
                    $season->relationLoaded('episodes') ? $season->episodes : [],
                ))
                ->values()
                ->all()),
            'episodes' => $this->whenLoaded('episodes', fn () => $this->episodes
                ->map(EpisodeMapper::publicEpisode(...))
                ->values()
                ->all()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ]);
    }
}
