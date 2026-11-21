<?php

namespace App\Support;

use App\Rules\YouTubeUrl;
use InvalidArgumentException;

/** CSV metadata is evidence supplied by its author, never proof of playability. */
final class YouTubeCatalog
{
    public const COLUMNS = ['title', 'youtube_title', 'video_kind', 'artwork_url', 'youtube_url', 'channel_url', 'series_playlist_url', 'artwork_type', 'artwork_check', 'youtube_check'];

    public const KINDS = ['full_movie', 'series_episode', 'mini_episode', 'trailer', 'film_promo', 'fan_meet'];

    public const CHANNELS = [
        'https://www.youtube.com/@pyoneplayofficial' => 'Pyone Play',
        'https://www.youtube.com/@maharofficialmovies' => 'Mahar',
    ];

    /** @return list<array<string, string>> */
    public static function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path) || filesize($path) > 2 * 1024 * 1024) {
            throw new InvalidArgumentException('CSV must be a readable file no larger than 2 MiB.');
        }
        $bytes = file_get_contents($path);
        if (! mb_check_encoding($bytes, 'UTF-8')) {
            throw new InvalidArgumentException('CSV must be valid UTF-8; convert its encoding explicitly.');
        }
        $file = fopen($path, 'rb');
        try {
            if (fread($file, 3) !== "\xEF\xBB\xBF") {
                rewind($file);
            }
            $headers = fgetcsv($file, 0, ',', '"', '');
            if (! is_array($headers) || count($headers) !== count(array_unique($headers)) || array_diff(self::COLUMNS, $headers) || array_diff($headers, self::COLUMNS)) {
                throw new InvalidArgumentException('CSV must have exactly the documented ten unique column names.');
            }
            $rows = [];
            while (($values = fgetcsv($file, 0, ',', '"', '')) !== false) {
                if ($values === [null]) {
                    continue;
                }
                if (count($values) !== count($headers)) {
                    throw new InvalidArgumentException('CSV field count mismatch at data row '.(count($rows) + 1).'.');
                }
                $rows[] = array_combine($headers, $values);
                if (count($rows) > 1000) {
                    throw new InvalidArgumentException('CSV exceeds the 1000-row safety limit.');
                }
            }
            if ($rows === []) {
                throw new InvalidArgumentException('CSV contains no data rows.');
            }

            return $rows;
        } finally {
            fclose($file);
        }
    }

    /** @return array{errors: list<string>, warnings: list<string>, video_id: ?string} */
    public static function validate(array $row): array
    {
        $errors = [];
        $warnings = [];
        foreach (['title' => 255, 'youtube_title' => 2000] as $column => $limit) {
            if (trim($row[$column]) === '' || mb_strlen($row[$column]) > $limit || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $row[$column])) {
                $errors[] = $column.' is missing, too long or contains control characters.';
            }
        }
        $id = YouTubeUrl::videoId($row['youtube_url']);
        if ($id === null) {
            $errors[] = 'Invalid HTTPS YouTube video URL or 11-character video ID.';
        }
        if (! in_array($row['video_kind'], self::KINDS, true)) {
            $errors[] = 'Unknown video_kind.';
        }
        if (! isset(self::CHANNELS[strtolower(rtrim($row['channel_url'], '/'))])) {
            $errors[] = 'Channel URL is not one of the two supplied channel handles.';
        }
        if ($row['artwork_url'] === '') {
            $warnings[] = 'Artwork missing; use responsive fallback.';
        } elseif ($id === null || ! preg_match('~^https://i\.ytimg\.com/vi/'.preg_quote($id, '~').'/(?:hqdefault|mqdefault|sddefault|maxresdefault|default)\.jpg$~D', $row['artwork_url']) || $row['artwork_type'] !== 'youtube_thumbnail') {
            $errors[] = 'Artwork must be an unmodified HTTPS YouTube thumbnail URL for this video ID.';
        }
        if ($row['series_playlist_url'] !== '') {
            $parts = parse_url($row['series_playlist_url']);
            parse_str($parts['query'] ?? '', $query);
            if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'www.youtube.com' || ($parts['path'] ?? '') !== '/playlist' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment']) || count(explode('&', $parts['query'] ?? '')) !== 1 || count($query) !== 1 || ! is_string($query['list'] ?? null) || ! preg_match('/^[A-Za-z0-9_-]{10,150}$/D', $query['list'])) {
                $errors[] = 'Invalid YouTube playlist URL syntax.';
            } else {
                $warnings[] = 'Playlist existence and video membership are unverified; never derive a season from a playlist.';
            }
        }
        $warnings[] = 'CSV verification labels are claims, not independent evidence. oEmbed does not guarantee browser embedding.';

        return ['errors' => $errors, 'warnings' => $warnings, 'video_id' => $id];
    }

    public static function metadataVerified(array $row, ?array $evidence): bool
    {
        $data = $evidence['oembed'] ?? [];

        return ($evidence['status'] ?? null) === 'verified_metadata'
            && YouTubeUrl::videoId($evidence['youtube_url'] ?? null) === YouTubeUrl::videoId($row['youtube_url'])
            && ($data['provider_name'] ?? null) === 'YouTube'
            && ($data['type'] ?? null) === 'video'
            && strtolower(rtrim($data['author_url'] ?? '', '/')) === strtolower(rtrim($row['channel_url'], '/'))
            && self::spaces($data['title'] ?? '') === self::spaces($row['youtube_title']);
    }

    public static function spaces(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /** Only numbers actually written in the supplied original title qualify. */
    public static function episodeNumber(string $title): ?int
    {
        $title = strtr($title, array_combine(preg_split('//u', '၀၁၂၃၄၅၆၇၈၉', -1, PREG_SPLIT_NO_EMPTY), range(0, 9)));
        preg_match_all('/(?:Episode\s*[- ]*|(?:အပိုင်း|ဇာတ်သိမ်းပိုင်း)\s*[- \/]*)([0-9]+)/iu', $title, $matches);
        $numbers = array_unique(array_map('intval', $matches[1]));

        return count($numbers) === 1 && reset($numbers) > 0 ? reset($numbers) : null;
    }
}
