<?php

namespace App\Support\Playback;

use App\Models\PlaybackSession;
use App\Models\VideoSource;
use App\Support\Contracts\PlaybackUrlSigner;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class NginxSecureLinkSigner implements PlaybackUrlSigner
{
    public const COOKIE = '__Secure-hl_playback';

    /** Exact ASCII bytes, no newline: v1|EXP|SCOPE|SESSION|SECRET. */
    public static function digest(int $expires, string $scope, string $session, #[\SensitiveParameter] string $secret): string
    {
        return rtrim(strtr(base64_encode(md5("v1|{$expires}|{$scope}|{$session}|{$secret}", true)), '+/', '-_'), '=');
    }

    public function sign(VideoSource $source, PlaybackSession $session): SignedPlaybackUrl
    {
        $cdn = config('ott.cdn');
        $secret = (string) ($cdn['secret'] ?? '');
        $domain = (string) ($cdn['cookie_domain'] ?? '');
        $base = rtrim((string) ($cdn['base_url'] ?? ''), '/');
        $apiHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $cdnHost = parse_url($base, PHP_URL_HOST);
        // Do not accept placeholders, query-bearing CDN bases or arbitrary domains.
        if (! request()->isSecure() || ! ($cdn['verified'] ?? false) || ! preg_match('/\A[0-9a-f]{64}\z/D', $secret)
            || ! preg_match('/\A[a-z0-9]+(?:[.-][a-z0-9]+)*\.[a-z]{2,}\z/D', $domain)
            || ! is_string($apiHost) || ! is_string($cdnHost)
            || ! str_ends_with($apiHost, '.'.$domain) || ! str_ends_with($cdnHost, '.'.$domain)
            || ! preg_match('~\Ahttps://[a-z0-9.-]+(?::[0-9]+)?\z~D', $base)) {
            $this->unavailable();
        }
        $registryPath = (string) ($cdn['assets_file'] ?? '');
        $registry = is_file($registryPath) ? json_decode(file_get_contents($registryPath), true) : null;
        $asset = is_array($registry) ? ($registry[(string) $source->id] ?? null) : null;
        $directory = $asset['origin_directory'] ?? '';
        $manifest = $asset['manifest'] ?? '';
        if (! is_array($asset) || (int) ($asset['movie_id'] ?? 0) !== (int) $source->movie_id
            || (int) $session->movie_id !== (int) $source->movie_id || (int) $session->video_source_id !== (int) $source->id
            || $source->kind !== 'hls' || ! $source->enabled
            || ! preg_match('~\A/(?:[A-Za-z0-9_-]+/)+\z~D', $directory)
            || ! preg_match('~\A[A-Za-z0-9_-][A-Za-z0-9_.-]*\.m3u8\z~D', $manifest)
            || $source->url !== 'https://cdn.goldendragon2000.com'.$directory.$manifest) {
            $this->unavailable();
        }
        $scope = '/hls/'.$source->movie_id.'/'.$source->id.'/';
        $ttl = (int) ($cdn['cookie_ttl_seconds'] ?? 300);
        if ($ttl < 30 || $ttl > 600) {
            $this->unavailable();
        }
        $expiresAt = now()->addSeconds($ttl)->min($session->expires_at)->startOfSecond();
        $expires = $expiresAt->getTimestamp();
        $signature = self::digest($expires, $scope, $session->public_id, $secret);
        $value = 'v1.'.$expires.'.'.$session->public_id.'.'.$signature;
        $cookie = Cookie::create(self::COOKIE, $value, $expires, $scope, $domain, true, true, false, Cookie::SAMESITE_LAX);

        return new SignedPlaybackUrl($base.$scope.$manifest, $expiresAt, $cookie);
    }

    private function unavailable(): never
    {
        throw new ServiceUnavailableHttpException(null, 'Protected CDN playback has not been verified or the asset is not approved.');
    }
}
