<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'subscription_expires_at' => now()->addYear(),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function withRole(string $slug = Role::SUBSCRIBER): static
    {
        return $this->afterCreating(function (User $user) use ($slug) {
            $role = Role::firstOrCreate(
                ['slug' => $slug],
                ['name' => Str::title($slug)],
            );

            $user->roles()->syncWithoutDetaching([$role->id]);
        });
    }

    public function subscriber(): static
    {
        return $this->withRole(Role::SUBSCRIBER);
    }

    public function administrator(): static
    {
        return $this->withRole(Role::ADMIN);
    }

    public function expiredSubscription(): static
    {
        return $this->state(fn () => [
            'subscription_expires_at' => now()->subDay(),
        ]);
    }

    public function lifetimeAccess(): static
    {
        return $this->state(fn () => [
            'subscription_expires_at' => null,
        ]);
    }
}
