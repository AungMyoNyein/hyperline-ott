<?php

namespace App\Support\Contracts;

use App\Models\PlaybackSession;
use App\Models\VideoSource;
use App\Support\Playback\SignedPlaybackUrl;

interface PlaybackUrlSigner
{
    /**
     * Authorise the manifest AND its child playlists, segments and keys at the CDN.
     * Implementations must not return the unrestricted origin URL. Expiry must
     * be no later than the session expiry. Credentials stay inside the provider.
     */
    public function sign(VideoSource $source, PlaybackSession $session): SignedPlaybackUrl;
}
