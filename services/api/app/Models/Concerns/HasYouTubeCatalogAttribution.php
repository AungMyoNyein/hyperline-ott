<?php

namespace App\Models\Concerns;

use App\Models\AdminAuditLog;
use App\Models\VideoSource;
use App\Rules\YouTubeUrl;
use App\Support\YouTubeCatalog;
use Illuminate\Database\Eloquent\Relations\MorphOne;

trait HasYouTubeCatalogAttribution
{
    public function youtubeCatalogImport(): MorphOne
    {
        return $this->morphOne(AdminAuditLog::class, 'target')->ofMany(['id' => 'max'], fn ($query) => $query->where('action', 'catalog.youtube.imported'));
    }

    /** Public whitelist only. Never expose batch/audit properties or video URLs. */
    public function youtubeAttribution(?VideoSource $source = null): ?array
    {
        $source ??= $this->videoSources->first(fn (VideoSource $s) => $s->kind === 'youtube' && $s->enabled);
        if (! $source || $source->kind !== 'youtube') {
            return null;
        }
        $properties = $this->youtubeCatalogImport?->properties ?? [];
        $metadata = $properties['metadata'] ?? [];
        if (($properties['video_id'] ?? null) !== YouTubeUrl::videoId($source->url)
            || ! isset(YouTubeCatalog::CHANNELS[strtolower(rtrim($metadata['channel_url'] ?? '', '/'))])) {
            return ['platform' => 'YouTube'];
        }

        return [
            'platform' => 'YouTube',
            'channel_name' => $properties['channel_name'],
            'channel_url' => $metadata['channel_url'],
            'youtube_title' => $metadata['youtube_title'],
            'video_kind' => $metadata['video_kind'],
            'playlist_url' => $metadata['series_playlist_url'] ?: null,
        ];
    }
}
