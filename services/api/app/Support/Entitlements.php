<?php

namespace App\Support;

use App\Models\Movie;
use App\Models\SeriesEpisode;
use App\Models\User;

/**
 * Phase 1 playback entitlement policy. Subscription billing is deliberately out
 * of scope for this stage, so the rules are narrow and documented:
 *
 * 1. Only a published movie can be played.
 * 2. Playback always requires an authenticated user (config: playback.require_authentication).
 * 3. A user is entitled while subscription_expires_at is in the future. A null
 *    expiry means no billing constraint has been recorded yet and is treated as
 *    entitled, so accounts created before billing ships keep working.
 * 4. Administrators are entitled to everything, for playback QA in the CMS.
 */
class Entitlements
{
    public const DENY_MOVIE_UNPUBLISHED = 'movie_unpublished';

    public const DENY_UNAUTHENTICATED = 'authentication_required';

    public const DENY_SUBSCRIPTION_EXPIRED = 'subscription_expired';

    public const DENY_NO_SOURCE = 'no_video_source';

    public static function denyReason(?User $user, Movie $movie): ?string
    {
        // Administrators are entitled to everything, drafts included, so the CMS can
        // verify a title before it goes live. Checked first by design.
        if ($user !== null && $user->isAdmin()) {
            return null;
        }

        if (! $movie->published) {
            return self::DENY_MOVIE_UNPUBLISHED;
        }

        if (config('ott.playback.require_authentication') && $user === null) {
            return self::DENY_UNAUTHENTICATED;
        }

        if ($user === null) {
            return null;
        }

        $expiresAt = $user->subscription_expires_at;

        if ($expiresAt === null) {
            return config('ott.playback.allow_missing_subscription') ? null : self::DENY_SUBSCRIPTION_EXPIRED;
        }

        return $expiresAt->isFuture() ? null : self::DENY_SUBSCRIPTION_EXPIRED;
    }

    /**
     * Episode playback follows the same policy as movies, with one addition:
     * the episode, its season AND its series must all be published for
     * non-administrators. The unpublished reason code is deliberately shared
     * with movies so the public error contract stays stable.
     */
    public static function denyReasonForEpisode(?User $user, SeriesEpisode $episode): ?string
    {
        if ($user !== null && $user->isAdmin()) {
            return null;
        }

        $episode->loadMissing('series', 'season');

        if (
            ! $episode->published
            || ! ($episode->season?->published ?? false)
            || ! ($episode->series?->published ?? false)
        ) {
            return self::DENY_MOVIE_UNPUBLISHED;
        }

        if (config('ott.playback.require_authentication') && $user === null) {
            return self::DENY_UNAUTHENTICATED;
        }

        if ($user === null) {
            return null;
        }

        $expiresAt = $user->subscription_expires_at;

        if ($expiresAt === null) {
            return config('ott.playback.allow_missing_subscription') ? null : self::DENY_SUBSCRIPTION_EXPIRED;
        }

        return $expiresAt->isFuture() ? null : self::DENY_SUBSCRIPTION_EXPIRED;
    }
}
