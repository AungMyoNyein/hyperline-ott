<?php

namespace App\Models;

use Database\Factories\SeriesEpisodeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeriesEpisode extends Model
{
    /** @use HasFactory<SeriesEpisodeFactory> */
    use HasFactory;

    protected $table = 'series_episodes';

    protected $fillable = [
        'series_id',
        'season_id',
        'episode_number',
        'title',
        'synopsis',
        'duration_seconds',
        'still_url',
        'published',
    ];

    protected function casts(): array
    {
        return [
            'episode_number' => 'integer',
            'duration_seconds' => 'integer',
            'published' => 'boolean',
        ];
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class);
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    /**
     * Full publication chain: the episode, its season and its series must all
     * be published before the episode is exposed to ordinary viewers.
     */
    public function isVisibleInCatalog(): bool
    {
        return $this->published
            && ($this->season?->published ?? false)
            && ($this->series?->published ?? false);
    }

    public function videoSources(): HasMany
    {
        return $this->hasMany(VideoSource::class, 'episode_id');
    }

    public function enabledVideoSources(): HasMany
    {
        return $this->videoSources()->where('enabled', true);
    }

    public function playbackSessions(): HasMany
    {
        return $this->hasMany(PlaybackSession::class, 'episode_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('published', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('episode_number');
    }

    /**
     * Mirrors Movie::hasPlayableSource(); the source URL itself is never
     * exposed through catalog payloads.
     */
    public function hasPlayableSource(): bool
    {
        if ($this->relationLoaded('videoSources')) {
            return $this->videoSources->contains(fn (VideoSource $source) => $source->isPlayable());
        }

        return $this->videoSources()->where('enabled', true)->whereIn('kind', VideoSource::SUPPORTED_KINDS)->exists();
    }
}
