<?php

namespace App\Models;

use Database\Factories\SeasonFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A season groups episodes inside exactly one series. Ownership is enforced by
 * the database: unique (series_id, season_number) plus the episodes' composite
 * foreign key (series_id, season_id) -> seasons(series_id, id), so an episode
 * can never point at a season of a different series.
 *
 * Season number 0 is the reserved "specials" bucket: it is a normal, fully
 * playable season, but catalog ordering places it last and next-episode
 * sequencing skips it (see Series::catalogOrder()).
 */
class Season extends Model
{
    /** @use HasFactory<SeasonFactory> */
    use HasFactory;

    public const SPECIALS = 0;

    protected $fillable = [
        'series_id',
        'season_number',
        'title',
        'synopsis',
        'poster_url',
        'backdrop_url',
        'published',
    ];

    protected function casts(): array
    {
        return [
            'season_number' => 'integer',
            'published' => 'boolean',
        ];
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class);
    }

    public function episodes(): HasMany
    {
        return $this->hasMany(SeriesEpisode::class)->orderBy('episode_number');
    }

    public function publishedEpisodes(): HasMany
    {
        return $this->episodes()->where('published', true);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('published', true);
    }

    public function isSpecials(): bool
    {
        return $this->season_number === self::SPECIALS;
    }
}
