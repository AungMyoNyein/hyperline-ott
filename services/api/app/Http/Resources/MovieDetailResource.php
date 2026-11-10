<?php

namespace App\Http\Resources;

use App\Models\VideoSource;
use Illuminate\Http\Request;

/**
 * Detail shape adds source *metadata* only. Labels and qualities let the player
 * build a track list; the origin URL still comes exclusively from a playback
 * session, so no endpoint hands out CDN locations to unentitled viewers.
 */
class MovieDetailResource extends MovieResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'sources' => $this->whenLoaded('videoSources', fn () => $this->videoSources
                ->where('enabled', true)
                ->map(fn (VideoSource $source) => [
                    'label' => $source->label,
                    'kind' => $source->kind,
                    'supported' => in_array($source->kind, VideoSource::SUPPORTED_KINDS, true),
                    'quality' => $source->quality,
                    'language' => $source->language,
                    'default' => $source->is_default,
                ])
                ->values()
                ->all()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ]);
    }
}
