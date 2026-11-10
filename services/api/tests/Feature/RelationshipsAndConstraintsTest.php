<?php

namespace Tests\Feature;

use App\Models\Genre;
use App\Models\Movie;
use App\Models\Permission;
use App\Models\PlaybackSession;
use App\Models\Role;
use App\Models\User;
use App\Models\VideoSource;
use App\Models\WatchProgress;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RelationshipsAndConstraintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_permissions_drive_the_ability_gates(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = User::factory()->administrator()->create();
        $viewer = User::factory()->subscriber()->create();

        $this->assertTrue($admin->isAdmin());
        $this->assertFalse($viewer->isAdmin());

        foreach (Permission::ALL as $ability) {
            $this->assertTrue($admin->hasPermission($ability), $ability.' should be granted to admin');
            $this->assertFalse($viewer->hasPermission($ability), $ability.' must not be granted to subscriber');
            $this->assertTrue($admin->can($ability));
            $this->assertFalse($viewer->can($ability));
        }

        $this->assertTrue($admin->can('movies.update', Movie::factory()->create()));
        $this->assertFalse($viewer->can('movies.update', Movie::factory()->create()));

        // Policies resolve through the same permissions, so a subscriber cannot
        // update or delete a title even with a valid session.
        $subject = Movie::factory()->create();
        $this->assertTrue($admin->can('update', $subject));
        $this->assertTrue($admin->can('delete', $subject));
        $this->assertFalse($viewer->can('update', $subject));
        $this->assertFalse($viewer->can('create', Movie::class));
    }

    public function test_movie_genre_and_source_relationships_round_trip(): void
    {
        $movie = Movie::factory()->create();
        $genre = Genre::factory()->create();
        $source = VideoSource::factory()->for($movie)->create();

        $movie->genres()->attach($genre);

        $this->assertSame($genre->id, $movie->genres->first()->id);
        $this->assertSame($movie->id, $genre->movies()->value('id'));
        $this->assertSame($movie->id, $source->movie->id);
        $this->assertSame($source->id, $movie->videoSources()->value('id'));
    }

    public function test_enabled_sources_exclude_disabled_sources(): void
    {
        $movie = Movie::factory()->create();
        $enabled = VideoSource::factory()->for($movie)->create(['enabled' => true]);
        VideoSource::factory()->for($movie)->create(['label' => 'disabled', 'enabled' => false]);

        $this->assertSame([$enabled->id], $movie->enabledVideoSources()->pluck('id')->all());
    }

    public function test_deleting_a_movie_cascades_to_sources_watchlists_and_sessions(): void
    {
        $movie = Movie::factory()->create();
        $user = User::factory()->create();

        VideoSource::factory()->for($movie)->create();
        $user->watchlist()->create(['movie_id' => $movie->id]);
        WatchProgress::factory()->for($user)->for($movie)->create();
        PlaybackSession::factory()->for($user)->for($movie)->create();

        $this->assertSame(2, DB::table('video_sources')->count() + DB::table('watchlists')->count());

        $movie->delete();

        $this->assertSame(0, DB::table('video_sources')->count());
        $this->assertSame(0, DB::table('watchlists')->count());
        $this->assertSame(0, DB::table('watch_progress')->count());
        $this->assertSame(0, DB::table('playback_sessions')->count());
    }

    public function test_deleting_a_genre_clears_the_pivot_and_deleting_a_user_clears_viewer_rows(): void
    {
        $genre = Genre::factory()->create();
        $movie = Movie::factory()->create();
        $movie->genres()->attach($genre);

        $user = User::factory()->create();
        $user->watchlist()->create(['movie_id' => $movie->id]);
        WatchProgress::factory()->for($user)->for($movie)->create();

        $genre->delete();
        $this->assertSame(0, DB::table('movie_genre')->count());

        $user->delete();
        $this->assertSame(0, DB::table('watchlists')->count());
        $this->assertSame(0, DB::table('watch_progress')->count());
        $this->assertDatabaseHas('movies', ['id' => $movie->id]);
    }

    public function test_unique_constraints_prevent_duplicate_viewer_rows(): void
    {
        $movie = Movie::factory()->create();
        $user = User::factory()->create();

        $user->watchlist()->create(['movie_id' => $movie->id]);

        $this->expectException(QueryException::class);
        DB::table('watchlists')->insert([
            'user_id' => $user->id,
            'movie_id' => $movie->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_duplicate_slug_is_rejected_by_the_database(): void
    {
        Movie::factory()->create(['slug' => 'unique-slug']);

        $this->expectException(QueryException::class);

        Movie::create(['title' => 'Clash', 'slug' => 'unique-slug']);
    }

    public function test_duplicate_source_label_for_one_movie_is_rejected(): void
    {
        $movie = Movie::factory()->create();
        VideoSource::factory()->for($movie)->create(['label' => 'main']);

        $this->expectException(QueryException::class);

        VideoSource::create([
            'movie_id' => $movie->id,
            'label' => 'main',
            'kind' => 'hls',
            'url' => 'https://cdn.example.test/vod/other.m3u8',
        ]);
    }

    public function test_foreign_keys_reject_orphan_rows(): void
    {
        $this->expectException(QueryException::class);

        DB::table('video_sources')->insert([
            'movie_id' => 424242,
            'label' => 'main',
            'kind' => 'hls',
            'url' => 'https://cdn.example.test/vod/a.m3u8',
            'is_default' => true,
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_preferences_and_subscription_fields_are_cast(): void
    {
        $user = User::factory()->create([
            'preferences' => ['language' => 'en', 'subtitles' => true],
            'avatar_url' => 'https://cdn.example.test/avatars/me.png',
            'subscription_expires_at' => now()->addDays(3),
        ]);

        $user->refresh();

        $this->assertSame(['language' => 'en', 'subtitles' => true], $user->preferences);
        $this->assertSame('https://cdn.example.test/avatars/me.png', $user->avatar_url);
        $this->assertTrue($user->subscription_expires_at->isFuture());
    }

    public function test_seeders_are_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $before = Role::query()->count();
        $permissions = Permission::query()->count();
        $this->seed(DatabaseSeeder::class);

        $this->assertSame($before, Role::query()->count());
        $this->assertSame($permissions, Permission::query()->count());
        $this->assertSame(0, User::query()->where('email', 'test@example.com')->count());
    }
}
