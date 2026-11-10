<?php

namespace App\Models;

use Database\Factories\MovieFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Movie extends Model
{
    /** @use HasFactory<MovieFactory> */
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'synopsis',
        'release_year',
        'duration_minutes',
        'poster_url',
        'backdrop_url',
        'featured',
        'published',
        'published_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'release_year' => 'integer',
            'duration_minutes' => 'integer',
            'featured' => 'boolean',
            'published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $movie) {
            if (blank($movie->slug)) {
                $movie->slug = Str::slug((string) $movie->title);
            }
        });
    }

    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(Genre::class, 'movie_genre')->orderBy('name');
    }

    public function videoSources(): HasMany
    {
        return $this->hasMany(VideoSource::class);
    }

    public function enabledVideoSources(): HasMany
    {
        return $this->videoSources()->where('enabled', true);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function watchlists(): HasMany
    {
        return $this->hasMany(Watchlist::class);
    }

    public function watchProgress(): HasMany
    {
        return $this->hasMany(WatchProgress::class);
    }

    public function playbackSessions(): HasMany
    {
        return $this->hasMany(PlaybackSession::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('published', true);
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('featured', true);
    }

    public function scopeGenre(Builder $query, Genre|string|null $genre): Builder
    {
        if (blank($genre)) {
            return $query;
        }

        $slug = $genre instanceof Genre ? $genre->slug : $genre;

        return $query->whereHas('genres', fn (Builder $q) => $q->where('slug', $slug));
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $needle = '%'.strtolower(trim($term)).'%';

        return $query->where(function (Builder $inner) use ($needle) {
            $inner->whereRaw('lower(title) like ?', [$needle])
                ->orWhereRaw('lower(coalesce(synopsis, \'\')) like ?', [$needle]);
        });
    }

    /**
     * Playback availability is exposed to clients, but never the source URL,
     * so catalog payloads cannot leak origin or CDN locations.
     */
    public function hasPlayableSource(): bool
    {
        if ($this->relationLoaded('videoSources')) {
            return $this->videoSources->contains(fn (VideoSource $source) => $source->isPlayable());
        }

        return $this->videoSources()->where('enabled', true)->whereIn('kind', VideoSource::SUPPORTED_KINDS)->exists();
    }
}
