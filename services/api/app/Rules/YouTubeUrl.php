<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Accepts standard YouTube watch/short links and resolves them to an 11-character
 * video id. Approved URLs are stored as canonical HTTPS watch links.
 */
class YouTubeUrl implements ValidationRule
{
    private const HOSTS = [
        'youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com',
        'youtu.be', 'www.youtu.be',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->videoId($value) === null) {
            $fail('The :attribute must be a YouTube link (youtube.com/watch?v=…, youtu.be/…, /embed/…, /shorts/… or /live/…) with a valid 11-character video id.');
        }
    }

    public static function videoId(mixed $value): ?string
    {
        $url = is_string($value) ? trim($value) : '';
        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            return null;
        }
        $host = strtolower($parts['host'] ?? '');
        if ($host === '' || ! in_array($host, self::HOSTS, true)) {
            return null;
        }

        $path = $parts['path'] ?? '';
        $id = null;

        if (str_ends_with($host, 'youtu.be')) {
            $id = preg_match('~^/([A-Za-z0-9_-]{11})/?$~', $path, $matches) ? $matches[1] : null;
        } elseif (preg_match('~^/(embed|shorts|live)/([A-Za-z0-9_-]{11})/?$~', $path, $matches)) {
            $id = $matches[2];
        } elseif ($path === '/watch') {
            $videoKeys = array_filter(explode('&', $parts['query'] ?? ''), fn ($part) => urldecode(explode('=', $part, 2)[0]) === 'v');
            if (count($videoKeys) !== 1) {
                return null;
            }
            parse_str($parts['query'] ?? '', $query);
            $id = $query['v'] ?? null;
        }

        return is_string($id) && preg_match('/^[A-Za-z0-9_-]{11}$/', $id) ? $id : null;
    }

    public static function canonicalUrl(mixed $value): ?string
    {
        $id = self::videoId($value);

        return $id === null ? null : 'https://www.youtube.com/watch?v='.$id;
    }
}
