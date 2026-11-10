<?php

namespace App\Support\Contracts;

use App\Models\PlaybackSession;
use App\Models\VideoSource;
use App\Support\Playback\SignedPlaybackUrl;

interface ExistingCdnAuthorizer
{
    /**
     * Implement ONLY the existing CDN operator's documented access protocol.
     * Preserve the CMS host/path. Authorize playlists, segments and keys, not
     * merely the master manifest. Never fall back to an unsigned stored URL.
     */
    public function authorize(VideoSource $source, PlaybackSession $session): SignedPlaybackUrl;
}
