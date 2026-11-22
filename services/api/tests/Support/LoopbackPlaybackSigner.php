<?php

namespace Tests\Support;

use App\Models\PlaybackSession;
use App\Models\VideoSource;
use App\Support\Contracts\PlaybackUrlSigner;
use App\Support\Playback\SignedPlaybackUrl;

/** Test transport fixture ONLY; not an implementation of the external CDN protocol. */
final class LoopbackPlaybackSigner implements PlaybackUrlSigner
{
    public function sign(VideoSource $source, PlaybackSession $session): SignedPlaybackUrl
    {
        $database = (string) config('database.connections.sqlite.database');
        if (! app()->environment('testing') || config('database.default') !== 'sqlite'
            || ! preg_match('~^/tmp/hyperline-browser-[A-Za-z0-9]{6}\.sqlite$~D', $database)
            || realpath($database) !== $database || is_link($database)
            || ! str_starts_with($source->url, 'https://127.0.0.1:18084/')) {
            throw new \LogicException('Loopback signer is restricted to the disposable browser fixture.');
        }
        $expiry = now()->addSeconds(8);

        // Deliberately a mock URL transport marker; it asserts NO CDN signing algorithm.
        return new SignedPlaybackUrl($source->url.'?fixture_session='.$session->public_id.'&fixture_exp='.$expiry->timestamp, $expiry);
    }
}
