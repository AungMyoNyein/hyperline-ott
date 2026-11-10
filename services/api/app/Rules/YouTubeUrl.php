<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Accepts standard YouTube watch/short links and resolves them to an 11-character
 * video id. The stored URL stays exactly what the administrator entered (HTTPS is
 * enforced separately by SafeStreamUrl); the web player converts it to an embed.
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
        $host = strtolower($parts['host'] ?? '');
        if ($host === '' || ! in_array($host, self::HOSTS, true)) {
            return null;
        }

        $path = $parts['path'] ?? '';
        $id = null;

        if (str_ends_with($host, 'youtu.be')) {
            $id = strtok(ltrim($path, '/'), '/');
        } elseif (preg_match('~^/(embed|shorts|live)/([^/?#]+)~', $path, $matches)) {
            $id = $matches[2];
        } elseif (str_starts_with($path, '/watch')) {
            parse_str($parts['query'] ?? '', $query);
            $id = $query['v'] ?? null;
        }

        return is_string($id) && preg_match('/^[A-Za-z0-9_-]{11}$/', $id) ? $id : null;
    }
}
