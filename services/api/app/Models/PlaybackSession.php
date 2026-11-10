<?php

namespace App\Models;

use Database\Factories\PlaybackSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PlaybackSession extends Model
{
    /** @use HasFactory<PlaybackSessionFactory> */
    use HasFactory;

    protected $fillable = [
        'public_id',
        'user_id',
        'movie_id',
        'episode_id',
        'video_source_id',
        'status',
        'started_at',
        'expires_at',
        'finished_at',
        'ip_address',
        'user_agent',
    ];

    protected $hidden = [
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $session) {
            $session->public_id ??= (string) Str::uuid();
        });
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

    public function videoSource(): BelongsTo
    {
        return $this->belongsTo(VideoSource::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->expires_at->isFuture();
    }
}
