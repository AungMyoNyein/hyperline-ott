<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\User;
use App\Models\VideoSource;
use App\Models\WatchProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseOneRegressionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Phase 2 update: DASH became playable through dash.js, so the Phase 1
     * "stored but unsupported" policy no longer applies to it. Preservation of
     * the stored row and source selection semantics are still asserted here;
     * an unknown kind keeps the old unsupported behavior.
     */
    public function test_dash_is_preserved_and_playable_and_unknown_kinds_stay_unsupported(): void
    {
        $movie = Movie::factory()->create();
        $dash = VideoSource::factory()->for($movie)->create(['kind' => 'dash', 'label' => 'dash', 'is_default' => true]);
        $token = User::factory()->subscriber()->create()->createToken('test')->plainTextToken;
        $this->getJson('/api/v1/movies/'.$movie->slug)->assertOk()
            ->assertJsonPath('data.playable', true)->assertJsonPath('data.sources.0.supported', true);
        $this->withToken($token)->postJson('/api/v1/playback/sessions', ['movie_id' => $movie->id])
            ->assertCreated()->assertJsonPath('data.source.kind', 'dash');
        $this->assertDatabaseHas('video_sources', ['id' => $dash->id, 'kind' => 'dash']);

        $dash->update(['kind' => 'bluray']);
        $this->getJson('/api/v1/movies/'.$movie->slug)->assertOk()
            ->assertJsonPath('data.playable', false)->assertJsonPath('data.sources.0.supported', false);
        VideoSource::factory()->for($movie)->create(['kind' => 'mp4', 'label' => 'mp4', 'is_default' => false]);
        $this->withToken($token)->postJson('/api/v1/playback/sessions', ['movie_id' => $movie->id])
            ->assertCreated()->assertJsonPath('data.source.kind', 'mp4');
    }

    public function test_renewal_rejects_a_source_that_becomes_unsupported(): void
    {
        $movie = Movie::factory()->create();
        $source = VideoSource::factory()->for($movie)->create();
        $token = User::factory()->subscriber()->create()->createToken('test')->plainTextToken;
        $id = $this->withToken($token)->postJson('/api/v1/playback/sessions', ['movie_id' => $movie->id])->assertCreated()->json('data.session_id');
        $source->update(['kind' => 'bluray']);
        $this->withToken($token)->postJson("/api/v1/playback/sessions/{$id}/renew")->assertForbidden();
    }

    public function test_saved_reads_hide_unpublished_movies_and_completion_removes_continue_watching(): void
    {
        $viewer = User::factory()->subscriber()->create();
        $token = $viewer->createToken('test')->plainTextToken;
        $draft = Movie::factory()->draft()->create();
        $target = Movie::factory()->create();
        foreach ([$draft, $target, Movie::factory()->create()] as $movie) {
            $viewer->watchlist()->create(['movie_id' => $movie->id]);
            WatchProgress::factory()->for($viewer)->for($movie)->create(['completed' => false]);
        }
        $this->withToken($token)->getJson('/api/v1/watchlist')->assertOk()->assertJsonCount(2, 'data');
        $this->withToken($token)->getJson('/api/v1/watch-progress')->assertOk()->assertJsonCount(2, 'data');
        $this->withToken($token)->getJson('/api/v1/watch-progress?movie_id='.$target->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.movie.id', $target->id);
        $this->withToken($token)->getJson('/api/v1/watch-progress?movie_id=bad')->assertUnprocessable();
        $this->withToken($token)->getJson('/api/v1/home')->assertOk()->assertJsonCount(2, 'data.continue_watching');
        $this->withToken($token)->postJson('/api/v1/watch-progress', ['movie_id' => $target->id, 'position_seconds' => 50, 'duration_seconds' => 50, 'completed' => true])->assertOk();
        $this->withToken($token)->getJson('/api/v1/home')->assertOk()->assertJsonCount(1, 'data.continue_watching');
    }

    public function test_progress_filter_is_scoped_to_owner_and_expired_home_is_anonymous(): void
    {
        $movie = Movie::factory()->create();
        WatchProgress::factory()->for(User::factory()->create())->for($movie)->create(['completed' => false]);
        $token = User::factory()->create()->createToken('test', ['*'], now()->subMinute())->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/home')->assertOk()->assertJsonCount(0, 'data.continue_watching');
        $this->withToken($token)->getJson('/api/v1/watch-progress?movie_id='.$movie->id)->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $fresh = User::factory()->create()->createToken('test')->plainTextToken;
        $this->withToken($fresh)->getJson('/api/v1/watch-progress?movie_id='.$movie->id)->assertOk()->assertJsonCount(0, 'data');
    }
}
