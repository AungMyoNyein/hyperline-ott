<?php

namespace Database\Factories;

use App\Models\Series;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Series>
 */
class SeriesFactory extends Factory
{
    protected $model = Series::class;

    public function definition(): array
    {
        $title = fake()->unique()->sentence(3);

        return [
            'title' => $title,
            'slug' => Str::slug($title),
            'synopsis' => fake()->paragraph(),
            'category' => 'vertical',
            'language' => 'Chinese',
            'release_year' => fake()->numberBetween(2015, 2026),
            'poster_url' => 'https://cdn.example.test/posters/series-'.fake()->unique()->numberBetween(1, 99999).'.jpg',
            'backdrop_url' => 'https://cdn.example.test/backdrops/series-'.fake()->unique()->numberBetween(1, 99999).'.jpg',
            'featured' => false,
            'published' => true,
            'published_at' => now(),
            'created_by' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => [
            'published' => false,
            'published_at' => null,
        ]);
    }

    public function featured(): static
    {
        return $this->state(fn () => [
            'featured' => true,
        ]);
    }

    public function createdBy(User $user): static
    {
        return $this->state(fn () => [
            'created_by' => $user->id,
        ]);
    }
}
