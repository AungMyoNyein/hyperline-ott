<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'slug' => $name,
            'name' => ucfirst($name),
            'description' => fake()->sentence(),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => [
            'slug' => Role::ADMIN,
            'name' => 'Administrator',
        ]);
    }

    public function subscriber(): static
    {
        return $this->state(fn () => [
            'slug' => Role::SUBSCRIBER,
            'name' => 'Subscriber',
        ]);
    }
}
