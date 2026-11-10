<?php

namespace App\Http\Resources;

use App\Models\Season;
use App\Models\SeriesEpisode;
use App\Models\VideoSource;

/**
 * Single place where episode and season payload shapes are built, so the flat
 * legacy `episodes` key and the nested `seasons` structure can never drift
 * apart. Public shapes carry source *metadata* only; the admin shape is the
 * only one that serialises origin URLs (same split as movies).
 */
class EpisodeMapper
{
    /**
     * @return array<string, mixed>
     */
    public static function publicEpisode(SeriesEpisode $episode): array
    {
        return [
            'id' => $episode->id,
            'season_id' => $episode->season_id,
            'season_number' => $episode->season_number ?? $episode->season?->season_number,
            'episode_number' => $episode->episode_number,
            'title' => $episode->title,
            'synopsis' => $episode->synopsis,
            'duration_seconds' => $episode->duration_seconds,
            'still_url' => $episode->still_url,
            'published' => $episode->published,
            'playable' => $episode->hasPlayableSource(),
            'sources' => $episode->relationLoaded('videoSources')
                ? $episode->videoSources
                    ->filter(fn (VideoSource $source) => $source->enabled)
                    ->map(fn (VideoSource $source) => [
                        'label' => $source->label,
                        'kind' => $source->kind,
                        'quality' => $source->quality,
                        'language' => $source->language,
                        'supported' => $source->isPlayable(),
                        'default' => $source->is_default,
                    ])
                    ->values()
                    ->all()
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function adminEpisode(SeriesEpisode $episode): array
    {
        return [
            'id' => $episode->id,
            'season_id' => $episode->season_id,
            'season_number' => $episode->season_number ?? $episode->season?->season_number,
            'episode_number' => $episode->episode_number,
            'title' => $episode->title,
            'synopsis' => $episode->synopsis,
            'duration_seconds' => $episode->duration_seconds,
            'still_url' => $episode->still_url,
            'published' => $episode->published,
            'sources' => $episode->relationLoaded('videoSources')
                ? $episode->videoSources
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
                    ->all()
                : null,
        ];
    }

    /**
     * @param  iterable<SeriesEpisode>  $episodes
     * @return array<string, mixed>
     */
    public static function publicSeason(Season $season, iterable $episodes): array
    {
        return [
            'id' => $season->id,
            'season_number' => $season->season_number,
            'title' => $season->title,
            'synopsis' => $season->synopsis,
            'poster_url' => $season->poster_url,
            'backdrop_url' => $season->backdrop_url,
            'episode_count' => is_countable($episodes) ? count($episodes) : iterator_count($episodes),
            'episodes' => array_map(self::publicEpisode(...), array_values(
                is_array($episodes) ? $episodes : iterator_to_array($episodes)
            )),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function adminSeason(Season $season): array
    {
        return [
            'id' => $season->id,
            'season_number' => $season->season_number,
            'title' => $season->title,
            'synopsis' => $season->synopsis,
            'poster_url' => $season->poster_url,
            'backdrop_url' => $season->backdrop_url,
            'published' => $season->published,
            'episode_count' => $season->relationLoaded('episodes') ? $season->episodes->count() : null,
            'episodes' => $season->relationLoaded('episodes')
                ? $season->episodes->map(self::adminEpisode(...))->values()->all()
                : null,
            'created_at' => $season->created_at,
            'updated_at' => $season->updated_at,
        ];
    }
}
