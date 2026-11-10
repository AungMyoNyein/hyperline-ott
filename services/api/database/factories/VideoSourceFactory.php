<?php

namespace Database\Factories;

use App\Models\Movie;
use App\Models\VideoSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VideoSource>
 */
class VideoSourceFactory extends Factory
{
    protected $model = VideoSource::class;

    public function definition(): array
    {
        return [
            'movie_id' => Movie::factory(),
            'label' => 'main',
            'kind' => 'hls',
            'url' => 'https://cdn.example.test/vod/'.fake()->unique()->numberBetween(100000, 999999).'/playlist.m3u8',
            'quality' => '1080p',
            'language' => 'en',
            'is_default' => true,
            'enabled' => true,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn () => [
            'enabled' => false,
            'is_default' => false,
        ]);
    }

    public function forEpisode(\App\Models\SeriesEpisode $episode): static
    {
        return $this->state(fn () => [
            'movie_id' => null,
            'episode_id' => $episode->id,
        ]);
    }
}
