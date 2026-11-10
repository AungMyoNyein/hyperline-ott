<?php

namespace Database\Factories;

use App\Models\Movie;
use App\Models\SeriesEpisode;
use App\Models\User;
use App\Models\WatchProgress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WatchProgress>
 */
class WatchProgressFactory extends Factory
{
    protected $model = WatchProgress::class;

    public function definition(): array
    {
        $duration = fake()->numberBetween(600, 7200);

        return [
            'user_id' => User::factory(),
            'movie_id' => Movie::factory(),
            'position_seconds' => fake()->numberBetween(0, $duration),
            'duration_seconds' => $duration,
            'completed' => false,
            'last_watched_at' => now(),
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'completed' => true,
        ]);
    }

    public function forEpisode(SeriesEpisode $episode): static
    {
        return $this->state(fn () => [
            'movie_id' => null,
            'episode_id' => $episode->id,
        ]);
    }
}
