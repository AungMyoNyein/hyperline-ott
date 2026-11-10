<?php

namespace App\Models;

use Database\Factories\WatchProgressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WatchProgress extends Model
{
    /** @use HasFactory<WatchProgressFactory> */
    use HasFactory;

    protected $table = 'watch_progress';

    protected $fillable = [
        'user_id',
        'movie_id',
        'episode_id',
        'position_seconds',
        'duration_seconds',
        'completed',
        'last_watched_at',
    ];

    protected function casts(): array
    {
        return [
            'position_seconds' => 'integer',
            'duration_seconds' => 'integer',
            'completed' => 'boolean',
            'last_watched_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(SeriesEpisode::class, 'episode_id');
    }
}
