<?php

namespace App\Support\Playback;

use Carbon\CarbonInterface;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Cookie;

final readonly class SignedPlaybackUrl
{
    public function __construct(public string $url, public CarbonInterface $expiresAt, public ?Cookie $cookie = null)
    {
        $parts = parse_url($url);
        if (! filter_var($url, FILTER_VALIDATE_URL) || ($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Signer must return an absolute HTTPS URL without credentials.');
        }
    }
}
