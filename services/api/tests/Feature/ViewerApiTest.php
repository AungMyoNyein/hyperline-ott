<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\User;
use App\Models\VideoSource;
use App\Models\WatchProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\ApiHelpers;
use Tests\TestCase;

class ViewerApiTest extends TestCase
{
    use ApiHelpers;
    use RefreshDatabase;

    private User $viewer;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->viewer = User::factory()->subscriber()->create(['email' => 'viewer@hyperline.test']);
        $this->token = $this->viewer->createToken('web')->plainTextToken;
    }

    public function test_registration_creates_a_subscriber_and_returns_a_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'New Viewer',
            'email' => 'new@hyperline.test',
            'password' => 'Passw0rd-Long-1',
            'password_confirmation' => 'Passw0rd-Long-1',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user.email', 'new@hyperline.test')
            ->assertJsonPath('data.user.roles.0', 'subscriber');

        $this->assertStringNotContainsString('Passw0rd-Long-1', $response->getContent());

        $this->withHeaders($this->bearer($response->json('data.token')))
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'new@hyperline.test');
    }

    public function test_registration_rejects_weak_and_duplicate_input(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'x',
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'other',
        ])->assertStatus(422)->assertJsonValidationErrors(['email', 'password']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Dup',
            'email' => 'viewer@hyperline.test',
            'password' => 'Passw0rd-Long-1',
            'password_confirmation' => 'Passw0rd-Long-1',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_viewer_login_and_logout(): void
    {
        $user = User::factory()->create([
            'email' => 'login@hyperline.test',
            'password' => 'Viewer-Secret-1',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'login@hyperline.test',
            'password' => 'Viewer-Secret-1',
        ])->assertOk();

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertNotNull($user->fresh()->last_login_at);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'login@hyperline.test',
            'password' => 'wrong',
        ])->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');

        $this->withHeaders($this->bearer($response->json('data.token')))
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertSame(1, PersonalAccessToken::query()->count());
    }

    public function test_protected_viewer_endpoints_reject_guests(): void
    {
        $urls = [
            ['get', '/api/v1/me'],
            ['get', '/api/v1/watchlist'],
            ['post', '/api/v1/watchlist'],
            ['get', '/api/v1/watch-progress'],
            ['post', '/api/v1/watch-progress'],
            ['post', '/api/v1/playback/sessions'],
            ['delete', '/api/v1/watchlist/1'],
        ];

        foreach ($urls as [$verb, $url]) {
            $this->{$verb.'Json'}($url, ['movie_id' => 1, 'position_seconds' => 10])
                ->assertStatus(401)
                ->assertJsonPath('code', 'unauthenticated');
        }
    }

    public function test_watchlist_can_be_added_listed_and_removed(): void
    {
        $movie = Movie::factory()->create();
        VideoSource::factory()->for($movie)->create();

        $this->withHeaders($this->bearer($this->token))
            ->postJson('/api/v1/watchlist', ['movie_id' => $movie->id])
            ->assertCreated()
            ->assertJsonPath('data.movie.slug', $movie->slug)
            ->assertJsonPath('data.movie.playable', true);

        // Re-adding is idempotent, not a duplicate row.
        $this->withHeaders($this->bearer($this->token))
            ->postJson('/api/v1/watchlist', ['movie_id' => $movie->id])
            ->assertOk();

        $this->assertSame(1, $this->viewer->watchlist()->count());

        $this->withHeaders($this->bearer($this->token))
            ->getJson('/api/v1/watchlist')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.movie.id', $movie->id);

        $this->withHeaders($this->bearer($this->token))
            ->deleteJson('/api/v1/watchlist/'.$movie->id)
            ->assertOk()
            ->assertJson(['status' => 'removed']);

        $this->withHeaders($this->bearer($this->token))
            ->deleteJson('/api/v1/watchlist/'.$movie->id)
            ->assertStatus(404);
    }

    public function test_watchlist_rejects_drafts_and_bad_input(): void
    {
        $draft = Movie::factory()->draft()->create();

        $this->withHeaders($this->bearer($this->token))
            ->postJson('/api/v1/watchlist', ['movie_id' => $draft->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['movie_id']);

        $this->withHeaders($this->bearer($this->token))
            ->postJson('/api/v1/watchlist', [])
            ->assertStatus(422);
    }

    public function test_watch_progress_is_upserted_per_viewer_and_movie(): void
    {
        $movie = Movie::factory()->create(['duration_minutes' => 100]);

        $this->withHeaders($this->bearer($this->token))
            ->postJson('/api/v1/watch-progress', [
                'movie_id' => $movie->id,
                'position_seconds' => 120,
                'duration_seconds' => 6000,
            ])->assertCreated()->assertJsonPath('data.position_seconds', 120);

        $this->withHeaders($this->bearer($this->token))
            ->postJson('/api/v1/watch-progress', [
                'movie_id' => $movie->id,
                'position_seconds' => 5400,
                'duration_seconds' => 6000,
                'completed' => true,
            ])->assertOk()->assertJsonPath('data.completed', true);

        $rows = WatchProgress::query()->where('user_id', $this->viewer->id)->get();

        $this->assertCount(1, $rows);
        $this->assertSame(5400, $rows->first()->position_seconds);

        $this->withHeaders($this->bearer($this->token))
            ->getJson('/api/v1/watch-progress')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_watch_progress_rejects_position_beyond_duration_and_drafts(): void
    {
        $movie = Movie::factory()->create();

        $this->withHeaders($this->bearer($this->token))
            ->postJson('/api/v1/watch-progress', [
                'movie_id' => $movie->id,
                'position_seconds' => 7000,
                'duration_seconds' => 6000,
            ])->assertStatus(422)->assertJsonValidationErrors(['position_seconds']);

        $this->withHeaders($this->bearer($this->token))
            ->postJson('/api/v1/watch-progress', [
                'movie_id' => Movie::factory()->draft()->create()->id,
                'position_seconds' => 10,
            ])->assertStatus(422)->assertJsonValidationErrors(['movie_id']);
    }

    public function test_continue_watching_lists_only_the_owner_and_skips_completed_titles(): void
    {
        $mine = Movie::factory()->create(['title' => 'Mine']);
        $finished = Movie::factory()->create(['title' => 'Finished']);
        $theirs = Movie::factory()->create(['title' => 'Theirs']);

        WatchProgress::factory()->for($this->viewer)->for($mine)->create(['completed' => false, 'position_seconds' => 300]);
        WatchProgress::factory()->for($this->viewer)->for($finished)->create(['completed' => true]);
        WatchProgress::factory()->for(User::factory()->create())->for($theirs)->create(['completed' => false]);

        $guest = collect($this->getJson('/api/v1/home')->assertOk()->json('data.continue_watching'));
        $owner = collect($this->withHeaders($this->bearer($this->token))
            ->getJson('/api/v1/home')->assertOk()->json('data.continue_watching'));

        $this->assertCount(0, $guest);
        $this->assertSame(['Mine'], $owner->pluck('title')->all());
        $this->assertSame(300, $owner->first()['position_seconds']);
    }

    public function test_one_viewer_cannot_read_another_viewers_data(): void
    {
        $other = User::factory()->create();
        WatchProgress::factory()->for($other)->for(Movie::factory()->create())->create();
        $other->watchlist()->create(['movie_id' => Movie::factory()->create()->id]);

        $this->withHeaders($this->bearer($this->token))->getJson('/api/v1/watch-progress')
            ->assertOk()->assertJsonCount(0, 'data');

        $this->withHeaders($this->bearer($this->token))->getJson('/api/v1/watchlist')
            ->assertOk()->assertJsonCount(0, 'data');
    }
}
