<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PlaybackDeniedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePlaybackSessionRequest;
use App\Http\Resources\PlaybackSessionResource;
use App\Models\Movie;
use App\Models\PlaybackSession;
use App\Models\SeriesEpisode;
use App\Models\VideoSource;
use App\Support\Contracts\PlaybackUrlSigner;
use App\Support\Entitlements;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class PlaybackSessionController extends Controller
{
    public function store(StorePlaybackSessionRequest $request): PlaybackSessionResource
    {
        $user = $request->user();

        if ($request->filled('episode_id')) {
            return $this->storeForEpisode($request, $user);
        }

        return $this->storeForMovie($request, $user);
    }

    private function storeForMovie(StorePlaybackSessionRequest $request, $user): PlaybackSessionResource
    {
        $movie = Movie::query()->with('videoSources')->findOrFail($request->integer('movie_id'));

        $reason = Entitlements::denyReason($user, $movie);

        if ($reason !== null) {
            throw new PlaybackDeniedException($reason, $this->message($reason));
        }

        $source = $this->selectSource($movie->videoSources, $request->input('source'));

        if ($source === null) {
            throw new PlaybackDeniedException(
                Entitlements::DENY_NO_SOURCE,
                $this->message(Entitlements::DENY_NO_SOURCE),
            );
        }

        return DB::transaction(function () use ($user, $movie, $source, $request) {
            $session = $user->playbackSessions()->create([
                'movie_id' => $movie->id,
                'video_source_id' => $source->id,
                'status' => 'active',
                'started_at' => now(),
                'expires_at' => now()->addSeconds(min((int) config('ott.playback.ttl_seconds'), (int) config('ott.cdn.max_session_seconds'))),
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
            ]);

            return $this->authorise($session, $source);
        });
    }

    private function storeForEpisode(StorePlaybackSessionRequest $request, $user): PlaybackSessionResource
    {
        $episode = SeriesEpisode::query()->with(['series', 'season', 'videoSources'])
            ->findOrFail($request->integer('episode_id'));

        $reason = Entitlements::denyReasonForEpisode($user, $episode);

        if ($reason !== null) {
            throw new PlaybackDeniedException($reason, $this->episodeMessage($reason));
        }

        $source = $this->selectSource($episode->videoSources, $request->input('source'));

        if ($source === null) {
            throw new PlaybackDeniedException(
                Entitlements::DENY_NO_SOURCE,
                $this->message(Entitlements::DENY_NO_SOURCE),
            );
        }

        return DB::transaction(function () use ($user, $episode, $source, $request) {
            $session = $user->playbackSessions()->create([
                'episode_id' => $episode->id,
                'video_source_id' => $source->id,
                'status' => 'active',
                'started_at' => now(),
                'expires_at' => now()->addSeconds(min((int) config('ott.playback.ttl_seconds'), (int) config('ott.cdn.max_session_seconds'))),
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
            ]);

            return $this->authorise($session, $source);
        });
    }

    public function renew(Request $request, PlaybackSession $session): PlaybackSessionResource
    {
        return DB::transaction(function () use ($request, $session) {
            $session = PlaybackSession::query()->lockForUpdate()->findOrFail($session->id);
            abort_unless((int) $session->user_id === (int) $request->user()->id, 404);

            if ($session->episode_id !== null) {
                return $this->renewEpisode($session, $request);
            }

            return $this->renewMovie($session, $request);
        });
    }

    private function renewMovie(PlaybackSession $session, Request $request): PlaybackSessionResource
    {
        $session->load(['movie', 'videoSource']);
        abort_unless($session->isActive(), 403, 'The playback session has expired or ended. Start playback again.');
        $reason = Entitlements::denyReason($request->user(), $session->movie);
        if ($reason !== null) {
            throw new PlaybackDeniedException($reason, $this->message($reason));
        }
        $source = $session->videoSource;
        abort_unless($source !== null && $source->isPlayable() && (int) $source->movie_id === (int) $session->movie_id, 403, 'The source is no longer available.');

        return $this->extend($session, $source);
    }

    private function renewEpisode(PlaybackSession $session, Request $request): PlaybackSessionResource
    {
        $session->load(['episode.series', 'episode.season', 'videoSource']);
        abort_unless($session->isActive(), 403, 'The playback session has expired or ended. Start playback again.');
        $episode = $session->episode;
        abort_unless($episode !== null, 403, 'The source is no longer available.');
        $reason = Entitlements::denyReasonForEpisode($request->user(), $episode);
        if ($reason !== null) {
            throw new PlaybackDeniedException($reason, $this->episodeMessage($reason));
        }
        $source = $session->videoSource;
        abort_unless($source !== null && $source->isPlayable() && (int) $source->episode_id === (int) $session->episode_id, 403, 'The source is no longer available.');

        return $this->extend($session, $source);
    }

    private function extend(PlaybackSession $session, VideoSource $source): PlaybackSessionResource
    {
        $maximum = $session->started_at->copy()->addSeconds((int) config('ott.cdn.max_session_seconds'));
        abort_unless($maximum->isFuture(), 403, 'Start a new playback session.');
        $session->expires_at = now()->addSeconds((int) config('ott.playback.ttl_seconds'))->min($maximum);
        $resource = $this->authorise($session, $source);
        $session->save();

        return $resource;
    }

    private function authorise(PlaybackSession $session, VideoSource $source): PlaybackSessionResource
    {
        $resource = PlaybackSessionResource::make($session->load(['videoSource', 'movie', 'episode.series', 'episode.season']));

        // YouTube sources are public embeds, not protected CDN transports: they
        // are returned unsigned (signature_required=false, no renewal) while the
        // entitlement checks above still gate who may create the session.
        if ($source->isEmbed()) {
            return $resource;
        }

        if (app()->isProduction() || config('ott.playback.signed_urls')) {
            $signed = app(PlaybackUrlSigner::class)->sign($source, $session);
            if ($signed->url === $source->url || ! $signed->expiresAt->isFuture()
                || $signed->expiresAt->greaterThan($session->expires_at)) {
                throw new ServiceUnavailableHttpException(null, 'The playback provider returned an invalid authorisation.');
            }
            $session->update(['expires_at' => $signed->expiresAt]);
            $resource->withPlaybackUrl($signed->url, $signed->cookie);
        }

        return $resource;
    }

    /**
     * A requested label is honoured only when that source is enabled, so a client
     * cannot ask an unpublished or disabled origin into existence.
     *
     * @param  \Illuminate\Support\Collection<int, VideoSource>  $sources
     */
    private function selectSource($sources, ?string $label): ?VideoSource
    {
        $enabled = $sources->filter(fn (VideoSource $source) => $source->isPlayable());

        if ($label !== null && $label !== '') {
            return $enabled->first(fn (VideoSource $source) => $source->label === $label);
        }

        return $enabled->first(fn (VideoSource $source) => $source->is_default) ?? $enabled->first();
    }

    private function message(string $reason): string
    {
        return match ($reason) {
            Entitlements::DENY_MOVIE_UNPUBLISHED => 'This title is not published.',
            Entitlements::DENY_UNAUTHENTICATED => 'Sign in to start playback.',
            Entitlements::DENY_SUBSCRIPTION_EXPIRED => 'Your subscription no longer covers this title.',
            Entitlements::DENY_NO_SOURCE => 'No playable source is available for this title.',
            default => 'Playback is not permitted.',
        };
    }

    private function episodeMessage(string $reason): string
    {
        return $reason === Entitlements::DENY_MOVIE_UNPUBLISHED
            ? 'This episode is not published.'
            : $this->message($reason);
    }
}
