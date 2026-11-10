<?php

namespace Database\Factories;

use App\Models\Movie;
use App\Models\PlaybackSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PlaybackSession>
 */
class PlaybackSessionFactory extends Factory
{
    protected $model = PlaybackSession::class;

    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'movie_id' => Movie::factory(),
            'video_source_id' => null,
            'status' => 'active',
            'started_at' => now(),
            'expires_at' => now()->addMinutes(10),
            'finished_at' => null,
            'ip_address' => '203.0.113.7',
            'user_agent' => 'HyperLine-Test/1.0',
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => 'finished',
            'expires_at' => now()->subMinute(),
            'finished_at' => now()->subMinute(),
        ]);
    }
}
