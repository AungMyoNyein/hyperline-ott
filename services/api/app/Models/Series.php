<?php

namespace App\Models;

use Database\Factories\SeriesFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Series extends Model
{
    /** @use HasFactory<SeriesFactory> */
    use HasFactory;

    protected $table = 'series';

    protected $fillable = [
        'title',
        'slug',
        'synopsis',
        'category',
        'language',
        'release_year',
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
            'featured' => 'boolean',
            'published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $series) {
            if (blank($series->slug)) {
                $series->slug = Str::slug((string) $series->title);
            }
        });
    }

    public function seasons(): HasMany
    {
        return $this->hasMany(Season::class)->orderByRaw(
            'case when season_number = 0 then 1 else 0 end'
        )->orderBy('season_number');
    }

    public function publishedSeasons(): HasMany
    {
        return $this->seasons()->where('published', true);
    }

    public function episodes(): HasMany
    {
        return $this->hasMany(SeriesEpisode::class)->orderBy('episode_number');
    }

    /**
     * Publication visibility is hierarchical: an episode is only exposed when
     * its own flag, its season's flag and this series' flag are all published.
     */
    public function publishedEpisodes(): HasMany
    {
        return $this->episodes()
            ->where('series_episodes.published', true)
            ->whereHas('season', fn (Builder $query) => $query->where('seasons.published', true));
    }

    /**
     * The flat catalog ordering used for public episode lists and the
     * next-episode rule: main seasons ascending, specials (season 0) last,
     * episodes ascending inside each season.
     */
    public function catalogEpisodes(): HasMany
    {
        return $this->publishedEpisodes()
            ->join('seasons', 'seasons.id', '=', 'series_episodes.season_id')
            ->select('series_episodes.*', 'seasons.season_number')
            // reorder() drops the relation's default episode_number ordering,
            // which would otherwise take precedence over the season grouping.
            ->reorder()
            ->orderByRaw('case when seasons.season_number = 0 then 1 else 0 end')
            ->orderBy('seasons.season_number')
            ->orderBy('series_episodes.episode_number');
    }

    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(Genre::class, 'series_genre');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('published', true);
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('featured', true);
    }

    public function scopeCategory(Builder $query, ?string $category): Builder
    {
        return blank($category) ? $query : $query->where('category', $category);
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
}
