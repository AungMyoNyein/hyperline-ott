<?php

namespace Database\Factories;

use App\Models\Season;
use App\Models\Series;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Season>
 */
class SeasonFactory extends Factory
{
    protected $model = Season::class;

    public function definition(): array
    {
        return [
            'series_id' => Series::factory(),
            'season_number' => 1,
            'title' => null,
            'synopsis' => null,
            'poster_url' => null,
            'backdrop_url' => null,
            'published' => true,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => [
            'published' => false,
        ]);
    }

    public function specials(): static
    {
        return $this->state(fn () => [
            'season_number' => Season::SPECIALS,
        ]);
    }

    public function numbered(int $seasonNumber): static
    {
        return $this->state(fn () => [
            'season_number' => $seasonNumber,
        ]);
    }
}
