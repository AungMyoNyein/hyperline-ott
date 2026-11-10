<?php

namespace Database\Factories;

use App\Models\Season;
use App\Models\Series;
use App\Models\SeriesEpisode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SeriesEpisode>
 */
class SeriesEpisodeFactory extends Factory
{
    protected $model = SeriesEpisode::class;

    public function definition(): array
    {
        return [
            'series_id' => Series::factory(),
            'season_id' => null,
            'episode_number' => fake()->unique()->numberBetween(1, 500),
            'title' => null,
            'synopsis' => null,
            'duration_seconds' => fake()->numberBetween(60, 2400),
            'still_url' => null,
            'published' => true,
        ];
    }

    /**
     * Keeps the composite (series_id, season_id) ownership coherent: an
     * explicit season wins and pulls the episode into that season's series;
     * otherwise the episode lands in the series' season 1, created on demand.
     * Tests that need deliberately mismatched identifiers insert raw rows.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (SeriesEpisode $episode) {
            if ($episode->season_id !== null) {
                $season = Season::query()->find($episode->season_id);
                if ($season !== null) {
                    $episode->series_id = $season->series_id;
                }

                return;
            }

            $season = Season::query()->firstOrCreate(
                ['series_id' => $episode->series_id, 'season_number' => 1],
                ['published' => true],
            );
            $episode->season_id = $season->id;
        });
    }

    public function draft(): static
    {
        return $this->state(fn () => [
            'published' => false,
        ]);
    }
}
