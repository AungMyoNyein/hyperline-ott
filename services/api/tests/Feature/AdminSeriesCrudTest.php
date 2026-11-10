<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\Season;
use App\Models\Series;
use App\Models\SeriesEpisode;
use App\Models\User;
use App\Models\VideoSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ApiHelpers;
use Tests\TestCase;

/**
 * Administrator Series/Season/Episode CRUD, including authorization, nested
 * identifier mismatches, validation contracts and audit coverage.
 */
class AdminSeriesCrudTest extends TestCase
{
    use ApiHelpers;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->administrator()->create();
    }

    /** @return array<string, string> */
    private function auth(): array
    {
        return $this->bearer($this->adminToken($this->admin));
    }

    public function test_guests_and_subscribers_cannot_reach_series_admin_crud(): void
    {
        $series = Series::factory()->create();
        $subscriber = User::factory()->create();
        $token = $subscriber->createToken('web')->plainTextToken;

        $this->postJson('/api/v1/admin/series', ['title' => 'X'])->assertStatus(401);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/v1/admin/series', ['title' => 'X'])->assertStatus(403);
        $this->withHeaders($this->bearer($token))
            ->deleteJson("/api/v1/admin/series/{$series->id}")->assertStatus(403);
        $this->withHeaders($this->bearer($token))
            ->postJson("/api/v1/admin/series/{$series->id}/seasons", ['season_number' => 1])->assertStatus(403);
    }

    public function test_admin_creates_a_series_with_an_automatic_season_one(): void
    {
        $response = $this->withHeaders($this->auth())->postJson('/api/v1/admin/series', [
            'title' => 'Brand new series',
            'synopsis' => 'Fresh.',
            'category' => 'vertical',
            'language' => 'Chinese',
            'published' => true,
        ])->assertCreated();

        $response->assertJsonPath('data.title', 'Brand new series')
            ->assertJsonPath('data.published', true)
            ->assertJsonPath('data.season_count', 1);

        $this->assertDatabaseHas('seasons', ['series_id' => $response->json('data.id'), 'season_number' => 1, 'published' => true]);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'series.created']);
    }

    public function test_series_creation_rejects_duplicate_slugs_and_invalid_fields(): void
    {
        Series::factory()->create(['slug' => 'taken']);

        $this->withHeaders($this->auth())->postJson('/api/v1/admin/series', [
            'title' => 'Taken', 'slug' => 'taken',
        ])->assertStatus(422)->assertJsonValidationErrors(['slug']);

        $this->withHeaders($this->auth())->postJson('/api/v1/admin/series', [
            'title' => 'Bad year', 'release_year' => 1200,
        ])->assertStatus(422)->assertJsonValidationErrors(['release_year']);
    }

    public function test_admin_updates_series_metadata_genres_and_publication(): void
    {
        $series = Series::factory()->create(['published' => false, 'published_at' => null]);
        $genre = \App\Models\Genre::factory()->create();

        $response = $this->withHeaders($this->auth())->patchJson("/api/v1/admin/series/{$series->id}", [
            'title' => 'Renamed',
            'genres' => [$genre->slug],
            'published' => true,
        ])->assertOk();

        $response->assertJsonPath('data.title', 'Renamed')
            ->assertJsonPath('data.published', true);
        $this->assertNotNull($series->fresh()->published_at);
        $this->assertDatabaseHas('series_genre', ['series_id' => $series->id, 'genre_id' => $genre->id]);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'series.updated']);
    }

    public function test_seasons_are_created_updated_and_deleted_with_cascade_and_audit(): void
    {
        $series = Series::factory()->create();

        $created = $this->withHeaders($this->auth())->postJson("/api/v1/admin/series/{$series->id}/seasons", [
            'season_number' => 2, 'title' => 'Second run',
        ])->assertCreated();
        $seasonId = $created->json('data.id');

        $this->withHeaders($this->auth())->postJson("/api/v1/admin/series/{$series->id}/seasons", [
            'season_number' => 2,
        ])->assertStatus(422)->assertJsonValidationErrors(['season_number']);

        $this->withHeaders($this->auth())->postJson("/api/v1/admin/series/{$series->id}/seasons", [
            'season_number' => 0, 'title' => 'Specials',
        ])->assertCreated();

        $this->withHeaders($this->auth())->patchJson("/api/v1/admin/series/{$series->id}/seasons/{$seasonId}", [
            'published' => false,
        ])->assertOk()->assertJsonPath('data.published', false);

        $episode = SeriesEpisode::factory()->for($series)->create(['season_id' => $seasonId, 'episode_number' => 1]);
        VideoSource::factory()->forEpisode($episode)->create();

        $this->withHeaders($this->auth())->deleteJson("/api/v1/admin/series/{$series->id}/seasons/{$seasonId}")
            ->assertOk()->assertJsonPath('episodes_deleted', 1);

        $this->assertDatabaseCount('series_episodes', 0);
        $this->assertDatabaseCount('video_sources', 0);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'series_season.deleted']);
    }

    public function test_nested_routes_reject_mismatched_series_and_season_identifiers(): void
    {
        $a = Series::factory()->create();
        $b = Series::factory()->create();
        $seasonOfB = Season::factory()->for($b)->create();
        $episodeOfB = SeriesEpisode::factory()->for($b)->create(['season_id' => $seasonOfB->id, 'episode_number' => 1]);

        // Season belongs to series B: addressing it through series A must 404.
        $this->withHeaders($this->auth())->patchJson("/api/v1/admin/series/{$a->id}/seasons/{$seasonOfB->id}", ['published' => false])
            ->assertStatus(404);
        $this->withHeaders($this->auth())->deleteJson("/api/v1/admin/series/{$a->id}/seasons/{$seasonOfB->id}")
            ->assertStatus(404);
        $this->withHeaders($this->auth())->postJson("/api/v1/admin/series/{$a->id}/seasons/{$seasonOfB->id}/episodes", [
            'episode_number' => 5, 'sources' => [$this->source()],
        ])->assertStatus(404);
        $this->withHeaders($this->auth())->patchJson("/api/v1/admin/series/{$a->id}/seasons/{$seasonOfB->id}/episodes/{$episodeOfB->id}", ['published' => false])
            ->assertStatus(404);

        $this->assertTrue($seasonOfB->fresh()->published);
    }

    public function test_episodes_are_created_with_sources_and_numbers_are_unique_per_season(): void
    {
        $series = Series::factory()->create();
        $s1 = Season::factory()->for($series)->numbered(1)->create();
        $s2 = Season::factory()->for($series)->numbered(2)->create();

        $payload = [
            'episode_number' => 1,
            'title' => 'Pilot',
            'duration_seconds' => 1200,
            'published' => true,
            'sources' => [$this->source()],
        ];

        $this->withHeaders($this->auth())
            ->postJson("/api/v1/admin/series/{$series->id}/seasons/{$s1->id}/episodes", $payload)
            ->assertOk()
            ->assertJsonPath('data.season_number', 1)
            ->assertJsonPath('data.episodes.0.title', 'Pilot');

        // Same number in a different season is fine.
        $this->withHeaders($this->auth())
            ->postJson("/api/v1/admin/series/{$series->id}/seasons/{$s2->id}/episodes", $payload)
            ->assertOk();

        // Duplicate within the same season is a 422.
        $this->withHeaders($this->auth())
            ->postJson("/api/v1/admin/series/{$series->id}/seasons/{$s1->id}/episodes", $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['episode_number']);

        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'series_episode.created']);
    }

    public function test_episode_creation_rejects_unsafe_stream_urls(): void
    {
        $series = Series::factory()->create();
        $season = Season::factory()->for($series)->create();

        $this->withHeaders($this->auth())->postJson("/api/v1/admin/series/{$series->id}/seasons/{$season->id}/episodes", [
            'episode_number' => 1,
            'sources' => [$this->source(['url' => 'https://private.example.test/secret.m3u8'])],
        ])->assertStatus(422)->assertJsonValidationErrors(['sources.0.url']);
    }

    public function test_episode_update_preserves_sources_when_omitted_and_replaces_when_supplied(): void
    {
        $series = Series::factory()->create();
        $season = Season::factory()->for($series)->create();
        $episode = SeriesEpisode::factory()->for($series)->create(['season_id' => $season->id, 'episode_number' => 1]);
        VideoSource::factory()->forEpisode($episode)->create(['label' => 'main']);

        // Metadata-only patch preserves the existing source row.
        $this->withHeaders($this->auth())->patchJson("/api/v1/admin/series/{$series->id}/seasons/{$season->id}/episodes/{$episode->id}", [
            'title' => 'Retitled',
        ])->assertOk();
        $this->assertDatabaseCount('video_sources', 1);
        $this->assertDatabaseHas('video_sources', ['episode_id' => $episode->id, 'label' => 'main']);

        // Supplying sources replaces the whole collection (Phase 1 semantics).
        $this->withHeaders($this->auth())->patchJson("/api/v1/admin/series/{$series->id}/seasons/{$season->id}/episodes/{$episode->id}", [
            'sources' => [
                $this->source(['label' => 'hd', 'url' => 'https://cdn.example.test/hd/playlist.m3u8', 'is_default' => true]),
                $this->source(['label' => 'sd', 'url' => 'https://cdn.example.test/sd/playlist.m3u8', 'quality' => '480p', 'is_default' => false]),
            ],
        ])->assertOk();

        $this->assertDatabaseCount('video_sources', 2);
        $this->assertDatabaseHas('video_sources', ['episode_id' => $episode->id, 'label' => 'hd', 'is_default' => true]);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'series_episode.updated']);
    }

    public function test_episode_source_validation_rejects_two_defaults_and_duplicate_labels(): void
    {
        $series = Series::factory()->create();
        $season = Season::factory()->for($series)->create();

        $this->withHeaders($this->auth())->postJson("/api/v1/admin/series/{$series->id}/seasons/{$season->id}/episodes", [
            'episode_number' => 1,
            'sources' => [
                $this->source(['label' => 'a', 'is_default' => true]),
                $this->source(['label' => 'a', 'url' => 'https://cdn.example.test/b/playlist.m3u8', 'is_default' => true]),
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['sources']);
    }

    public function test_deleting_an_episode_removes_its_sources_and_is_audited(): void
    {
        $series = Series::factory()->create();
        $season = Season::factory()->for($series)->create();
        $episode = SeriesEpisode::factory()->for($series)->create(['season_id' => $season->id, 'episode_number' => 7]);
        VideoSource::factory()->forEpisode($episode)->create();

        $this->withHeaders($this->auth())
            ->deleteJson("/api/v1/admin/series/{$series->id}/seasons/{$season->id}/episodes/{$episode->id}")
            ->assertOk()->assertJsonPath('status', 'deleted');

        $this->assertDatabaseCount('series_episodes', 0);
        $this->assertDatabaseCount('video_sources', 0);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'series_episode.deleted']);
    }

    public function test_deleting_a_series_cascades_through_seasons_and_episodes(): void
    {
        $series = Series::factory()->create();
        $season = Season::factory()->for($series)->create();
        $episode = SeriesEpisode::factory()->for($series)->create(['season_id' => $season->id, 'episode_number' => 1]);
        VideoSource::factory()->forEpisode($episode)->create();

        $this->withHeaders($this->auth())->deleteJson("/api/v1/admin/series/{$series->id}")
            ->assertOk()->assertJsonPath('status', 'deleted');

        $this->assertDatabaseCount('series', 0);
        $this->assertDatabaseCount('seasons', 0);
        $this->assertDatabaseCount('series_episodes', 0);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'series.deleted']);
    }

    public function test_legacy_flat_series_and_episode_routes_keep_working(): void
    {
        $series = Series::factory()->create();
        $episode = SeriesEpisode::factory()->for($series)->create(['episode_number' => 3]);

        $this->withHeaders($this->auth())->patchJson("/api/v1/admin/series/{$series->id}", ['published' => false])
            ->assertOk()->assertJsonPath('data.published', false);

        $this->withHeaders($this->auth())->patchJson("/api/v1/admin/series/{$series->id}/episodes/{$episode->id}", ['published' => false])
            ->assertOk();

        $this->assertFalse($episode->fresh()->published);

        // The legacy flat route also rejects cross-series episodes.
        $other = Series::factory()->create();
        $this->withHeaders($this->auth())->patchJson("/api/v1/admin/series/{$other->id}/episodes/{$episode->id}", ['published' => true])
            ->assertStatus(404);
    }

    public function test_admin_series_index_reports_season_counts_and_supports_filters(): void
    {
        $published = Series::factory()->create();
        Season::factory()->for($published)->numbered(1)->create();
        Season::factory()->for($published)->numbered(2)->create();
        Series::factory()->draft()->create(['title' => 'Hidden draft series']);

        $this->withHeaders($this->auth())->getJson('/api/v1/admin/series?published=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.season_count', 2);

        $this->withHeaders($this->auth())->getJson('/api/v1/admin/series?q=Hidden')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Hidden draft series');
    }

    public function test_admin_series_show_returns_nested_seasons_and_flat_episodes(): void
    {
        $series = Series::factory()->create();
        $s1 = Season::factory()->for($series)->numbered(1)->create();
        $s2 = Season::factory()->for($series)->numbered(2)->create();
        SeriesEpisode::factory()->for($series)->create(['season_id' => $s1->id, 'episode_number' => 1]);
        SeriesEpisode::factory()->for($series)->create(['season_id' => $s2->id, 'episode_number' => 1]);

        $response = $this->withHeaders($this->auth())->getJson("/api/v1/admin/series/{$series->id}")->assertOk();

        $response->assertJsonCount(2, 'data.seasons')
            ->assertJsonPath('data.seasons.0.season_number', 1)
            ->assertJsonPath('data.seasons.1.season_number', 2)
            ->assertJsonCount(2, 'data.episodes');
    }

    public function test_audit_snapshot_records_season_structure_without_source_urls(): void
    {
        $series = Series::factory()->create();
        $season = Season::factory()->for($series)->create();

        $this->withHeaders($this->auth())->postJson("/api/v1/admin/series/{$series->id}/seasons/{$season->id}/episodes", [
            'episode_number' => 1,
            'sources' => [$this->source(['url' => 'https://cdn.example.test/secret-origin/playlist.m3u8'])],
        ])->assertOk();

        $log = AdminAuditLog::where('action', 'series_episode.created')->firstOrFail();
        $this->assertStringNotContainsString('secret-origin', json_encode($log->properties));
    }
}
