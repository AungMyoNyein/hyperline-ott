<?php

namespace App\Support\Playback;

use App\Models\PlaybackSession;
use App\Models\VideoSource;
use App\Support\Contracts\ExistingCdnAuthorizer;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class UnconfiguredExistingCdnAuthorizer implements ExistingCdnAuthorizer
{
    public function authorize(VideoSource $source, PlaybackSession $session): SignedPlaybackUrl
    {
        throw new ServiceUnavailableHttpException(null, 'The existing CDN access-control protocol has not been configured.');
    }
}
