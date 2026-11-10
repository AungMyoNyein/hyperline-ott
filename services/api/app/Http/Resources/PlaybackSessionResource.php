<?php

namespace App\Http\Resources;

use App\Models\PlaybackSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * @mixin PlaybackSession
 */
class PlaybackSessionResource extends JsonResource
{
    private ?string $playbackUrl = null;

    private ?Cookie $playbackCookie = null;

    public function withPlaybackUrl(string $url, ?Cookie $cookie = null): static
    {
        $this->playbackUrl = $url;
        $this->playbackCookie = $cookie;

        return $this;
    }

    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Pragma', 'no-cache');
        if ($this->playbackCookie !== null) {
            $response->headers->setCookie($this->playbackCookie);
        }
    }

    public function toArray(Request $request): array
    {
        return [
            'session_id' => $this->public_id,
            'movie' => $this->episode_id === null ? [
                'id' => $this->movie_id,
                'slug' => $this->movie?->slug,
                'title' => $this->movie?->title,
            ] : null,
            'episode' => $this->episode_id === null ? null : [
                'id' => $this->episode_id,
                'episode_number' => $this->episode?->episode_number,
                'title' => $this->episode?->title,
                'season' => [
                    'id' => $this->episode?->season?->id,
                    'season_number' => $this->episode?->season?->season_number,
                ],
                'series' => [
                    'id' => $this->episode?->series?->id,
                    'slug' => $this->episode?->series?->slug,
                    'title' => $this->episode?->series?->title,
                ],
            ],
            'source' => $this->videoSource === null ? null : [
                'label' => $this->videoSource->label,
                'kind' => $this->videoSource->kind,
                'quality' => $this->videoSource->quality,
                'language' => $this->videoSource->language,
            ],

            'url' => $this->playbackUrl ?? $this->videoSource?->url,
            'signature_required' => $this->playbackUrl !== null,
            'credentials_required' => $this->playbackCookie !== null,
            'renew_after_seconds' => $this->playbackUrl === null ? null : max(1, (int) floor(now()->diffInSeconds($this->expires_at, false) * 0.6)),
            'expires_at' => $this->expires_at,
        ];
    }
}
