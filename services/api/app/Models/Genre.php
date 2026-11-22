<?php

namespace App\Models;

use Database\Factories\GenreFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Genre extends Model
{
    /** @use HasFactory<GenreFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'name_my',
        'enabled',
        'sort_order',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $genre) {
            if (blank($genre->slug)) {
                $genre->slug = Str::slug((string) $genre->name);
            }
        });
    }

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'sort_order' => 'integer'];
    }

    public function series(): BelongsToMany
    {
        return $this->belongsToMany(Series::class, 'series_genre');
    }

    public function movies(): BelongsToMany
    {
        return $this->belongsToMany(Movie::class, 'movie_genre');
    }
}
