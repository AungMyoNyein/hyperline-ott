<?php

namespace App\Support\Playback;

use App\Models\PlaybackSession;
use App\Models\VideoSource;
use App\Support\Contracts\ExistingCdnAuthorizer;
use App\Support\Contracts\PlaybackUrlSigner;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class ExistingCdnPlaybackSigner implements PlaybackUrlSigner
{
    public function __construct(private ExistingCdnAuthorizer $authorizer) {}

    public function sign(VideoSource $source, PlaybackSession $session): SignedPlaybackUrl
    {
        if (! config('ott.external_cdn.verified') || $source->kind !== 'hls' || ! $source->enabled
            || (int) $source->movie_id !== (int) $session->movie_id || (int) $source->id !== (int) $session->video_source_id) {
            $this->unavailable();
        }
        $original = $this->urlParts($source->url);
        $signed = $this->authorizer->authorize($source, $session);
        $result = $this->urlParts($signed->url);
        // No invented /hls namespace, URL proxy, new hostname or source remapping.
        // api.zentunnel.net cannot issue cookies for goldendragon2000.com.
        if ($result['path'] !== $original['path'] || $signed->cookie !== null
            || $signed->url === $source->url || ! $signed->expiresAt->isFuture()
            || $signed->expiresAt->greaterThan($session->expires_at)) {
            $this->unavailable();
        }

        return $signed;
    }

    private function urlParts(string $url): array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || strtolower($parts['host'] ?? '') !== 'cdn.goldendragon2000.com'
            || (isset($parts['port']) && $parts['port'] !== 443)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || ! str_ends_with($parts['path'] ?? '', '.m3u8')) {
            $this->unavailable();
        }

        return $parts;
    }

    private function unavailable(): never
    {
        throw new ServiceUnavailableHttpException(null, 'Existing CDN playback is not verified or the authorizer returned an incompatible response.');
    }
}
