<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Support\Str;

trait ApiHelpers
{
    protected function adminToken(User $admin): string
    {
        return $admin->createToken('cms', ['cms'])->plainTextToken;
    }

    /**
     * @return array<string, string>
     */
    protected function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function source(array $overrides = []): array
    {
        return array_merge([
            'label' => 'main',
            'kind' => 'hls',
            'url' => 'https://cdn.example.test/vod/title/playlist.m3u8',
            'quality' => '1080p',
            'language' => 'en',
            'is_default' => true,
            'enabled' => true,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function moviePayload(array $overrides = []): array
    {
        $suffix = Str::lower(Str::random(6));
        $title = (string) ($overrides['title'] ?? 'Sample Title '.$suffix);

        return array_merge([
            'title' => $title,
            'slug' => Str::slug($title).'-'.$suffix,
            'synopsis' => 'A short synopsis for testing.',
            'release_year' => 2024,
            'duration_minutes' => 118,
            'poster_url' => 'https://cdn.example.test/posters/a.jpg',
            'backdrop_url' => 'https://cdn.example.test/backdrops/a.jpg',
            'featured' => false,
            'published' => true,
            'genres' => [],
            'sources' => [$this->source()],
        ], $overrides);
    }
}
