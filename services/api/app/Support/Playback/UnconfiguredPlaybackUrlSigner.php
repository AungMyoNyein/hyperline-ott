<?php

namespace App\Support\Playback;

use App\Models\PlaybackSession;
use App\Models\VideoSource;
use App\Support\Contracts\PlaybackUrlSigner;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class UnconfiguredPlaybackUrlSigner implements PlaybackUrlSigner
{
    public function sign(VideoSource $source, PlaybackSession $session): SignedPlaybackUrl
    {
        throw new ServiceUnavailableHttpException(null, 'Protected playback is not configured. Please contact support.');
    }
}
