<?php

namespace App\Models;

use Database\Factories\VideoSourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoSource extends Model
{
    /** @use HasFactory<VideoSourceFactory> */
    use HasFactory;

    /**
     * Kinds the viewer player can actually render: HLS (hls.js), MP4 (native),
     * DASH (dash.js) and YouTube (embedded iframe). YouTube is an embed, not a
     * signed transport: its URL is public by design and is returned unsigned
     * by playback sessions; entitlement checks still gate session creation.
     */
    public const SUPPORTED_KINDS = ['hls', 'mp4', 'dash', 'youtube'];

    public function isPlayable(): bool
    {
        return $this->enabled && in_array($this->kind, self::SUPPORTED_KINDS, true);
    }

    public function isEmbed(): bool
    {
        return $this->kind === 'youtube';
    }

    protected $fillable = [
        'movie_id',
        'episode_id',
        'label',
        'kind',
        'url',
        'quality',
        'language',
        'is_default',
        'enabled',
    ];

    /**
     * Stream locations stay server-side: public catalog and detail responses
     * are built from these models and must never serialise the URL.
     *
     * @var list<string>
     */
    protected $hidden = [
        'url',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'enabled' => 'boolean',
        ];
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
