<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\Genre;
use App\Models\Movie;
use App\Models\User;
use App\Models\VideoSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ApiHelpers;
use Tests\TestCase;

class AdminMovieCrudTest extends TestCase
{
    use ApiHelpers;
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = $this->adminToken(User::factory()->administrator()->create());
    }

    private function asAdmin(): static
    {
        return $this->withHeaders($this->bearer($this->token));
    }

    public function test_administrator_can_create_a_movie_with_a_separate_source(): void
    {
        $genre = Genre::factory()->create(['name' => 'Action', 'slug' => 'action']);

        $response = $this->asAdmin()->postJson('/api/v1/admin/movies', $this->moviePayload([
            'title' => 'Wolf of Wall Street',
            'synopsis' => 'A broker trades too hard.',
            'genres' => ['action'],
            'featured' => true,
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.title', 'Wolf of Wall Street')
            ->assertJsonPath('data.published', true)
            ->assertJsonPath('data.genres.0.slug', 'action')
            ->assertJsonPath('data.sources.0.url', 'https://cdn.example.test/vod/title/playlist.m3u8');

        $movie = Movie::query()->firstOrFail();

        $this->assertSame($genre->id, $movie->genres->first()->id);
        $this->assertSame(1, $movie->videoSources()->count());
        $this->assertTrue((bool) $movie->videoSources()->first()->is_default);
        $this->assertNotNull($movie->published_at);
        $this->assertDatabaseHas('movies', ['id' => $movie->id, 'created_by' => User::query()->value('id')]);

        // The same row is served to the CMS with the origin, and to the catalog
        // without it.
        $this->asGuest();

        $public = $this->getJson('/api/v1/movies/'.$movie->slug)->assertOk();

        $this->assertStringNotContainsString('m3u8', $public->getContent());
    }

    public function test_slug_is_derived_from_the_title_when_omitted(): void
    {
        $payload = $this->moviePayload(['title' => 'The  Great   Escape']);
        unset($payload['slug']);

        $this->asAdmin()->postJson('/api/v1/admin/movies', $payload)
            ->assertCreated()
            ->assertJsonPath('data.slug', 'the-great-escape');
    }

    public function test_a_draft_starts_unpublished_without_a_published_timestamp(): void
    {
        $this->asAdmin()->postJson('/api/v1/admin/movies', $this->moviePayload(['published' => false]))
            ->assertCreated()
            ->assertJsonPath('data.published', false);

        $movie = Movie::query()->firstOrFail();

        $this->assertNull($movie->published_at);
        $this->assertFalse($movie->published);

        $this->asGuest();

        $this->getJson('/api/v1/movies/'.$movie->slug)->assertNotFound();
    }

    public function test_stream_urls_are_rejected_before_they_can_reach_an_internal_host(): void
    {
        $cases = [
            'plain http' => ['url' => 'http://cdn.example.test/vod/a.m3u8'],
            'loopback host' => ['url' => 'https://127.0.0.1/vod/a.m3u8'],
            'private range host' => ['url' => 'https://10.1.2.3/vod/a.m3u8'],
            'cloud metadata address' => ['url' => 'https://169.254.169.254/latest/meta-data'],
            'cgnat address' => ['url' => 'https://100.64.1.1/vod/a.m3u8'],
            'host resolving privately' => ['url' => 'https://private.example.test/vod/a.m3u8'],
            'host with mixed answers' => ['url' => 'https://mixed.example.test/vod/a.m3u8'],
            'unresolvable host' => ['url' => 'https://nowhere.invalid.test/vod/a.m3u8'],
            'embedded credentials' => ['url' => 'https://user:pass@cdn.example.test/vod/a.m3u8'],
            'non standard port' => ['url' => 'https://cdn.example.test:8443/vod/a.m3u8'],
            'not a manifest' => ['url' => 'https://cdn.example.test/vod/a.mp4'],
        ];

        foreach ($cases as $label => $override) {
            $response = $this->asAdmin()->postJson('/api/v1/admin/movies', $this->moviePayload([
                'sources' => [$this->source($override)],
            ]));

            $response->assertStatus(422);
            $this->assertArrayHasKey(
                'sources.0.url',
                $response->json('errors'),
                $label.' should be rejected with a field error on sources.0.url',
            );
        }

        $this->assertSame(0, Movie::query()->count());
        $this->assertSame(0, VideoSource::query()->count());
    }

    public function test_stream_url_accepts_a_public_manifest_host(): void
    {
        $this->asAdmin()->postJson('/api/v1/admin/movies', $this->moviePayload([
            'sources' => [$this->source(['url' => 'https://other-cdn.example.test/vod/a.m3u8'])],
        ]))->assertCreated();

        $this->assertDatabaseHas('video_sources', ['url' => 'https://other-cdn.example.test/vod/a.m3u8']);
    }

    public function test_metadata_and_source_validation_rules(): void
    {
        $this->asAdmin()->postJson('/api/v1/admin/movies', ['synopsis' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'sources']);

        // Sources must be their own rows, never a column on the movie.
        $this->asAdmin()->postJson('/api/v1/admin/movies', $this->moviePayload(['hls_url' => 'https://cdn.example.test/vod/a.m3u8']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['hls_url']);

        $this->asAdmin()->postJson('/api/v1/admin/movies', $this->moviePayload(['release_year' => 1899]))
            ->assertStatus(422)->assertJsonValidationErrors(['release_year']);

        $this->asAdmin()->postJson('/api/v1/admin/movies', $this->moviePayload(['duration_minutes' => 0]))
            ->assertStatus(422)->assertJsonValidationErrors(['duration_minutes']);

        $this->asAdmin()->postJson('/api/v1/admin/movies', $this->moviePayload(['poster_url' => 'http://cdn.example.test/a.jpg']))
            ->assertStatus(422)->assertJsonValidationErrors(['poster_url']);

        $this->asAdmin()->postJson('/api/v1/admin/movies', $this->moviePayload(['genres' => ['nope']]))
            ->assertStatus(422)->assertJsonValidationErrors(['genres.0']);

        $this->asAdmin()->postJson('/api/v1/admin/movies', $this->moviePayload(['sources' => []]))
            ->assertStatus(422)->assertJsonValidationErrors(['sources']);

        $this->asAdmin()->postJson('/api/v1/admin/movies', $this->moviePayload(['sources' => [
            $this->source(['is_default' => true]),
            $this->source(['label' => 'backup', 'is_default' => true]),
        ]]))->assertStatus(422)->assertJsonValidationErrors(['sources']);

        $this->asAdmin()->postJson('/api/v1/admin/movies', $this->moviePayload(['sources' => [
            $this->source(['label' => 'main']),
            $this->source(['label' => 'main', 'url' => 'https://cdn.example.test/vod/b.m3u8']),
        ]]))->assertStatus(422)->assertJsonValidationErrors(['sources']);
    }

    public function test_duplicate_slug_is_rejected(): void
    {
        $existing = Movie::factory()->create(['slug' => 'taken-slug']);

        $this->asAdmin()->postJson('/api/v1/admin/movies', $this->moviePayload(['slug' => $existing->slug]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['slug']);
    }

    public function test_administrator_can_update_a_movie_and_replace_its_sources(): void
    {
        $movie = Movie::factory()->draft()->create();
        VideoSource::factory()->for($movie)->create(['label' => 'main', 'url' => 'https://cdn.example.test/vod/old.m3u8']);

        $genre = Genre::factory()->create(['name' => 'Drama', 'slug' => 'drama']);

        $this->asAdmin()->putJson('/api/v1/admin/movies/'.$movie->id, [
            'published' => true,
            'genres' => ['drama'],
            'sources' => [
                $this->source(['label' => 'hd', 'url' => 'https://cdn.example.test/vod/new-hd.m3u8', 'is_default' => true]),
                $this->source(['label' => 'sd', 'url' => 'https://cdn.example.test/vod/new-sd.m3u8', 'quality' => '480p', 'is_default' => false]),
            ],
        ])->assertOk()
            ->assertJsonPath('data.published', true)
            ->assertJsonCount(2, 'data.sources');

        $movie->refresh()->load(['genres', 'videoSources']);

        $this->assertTrue($movie->published);
        $this->assertNotNull($movie->published_at);
        $this->assertSame(['drama'], $movie->genres->pluck('slug')->all());
        $this->assertSame(['hd', 'sd'], $movie->videoSources->pluck('label')->sort()->values()->all());
        $this->assertSame(0, VideoSource::query()->where('url', 'https://cdn.example.test/vod/old.m3u8')->count());

        // A patch without sources must leave existing rows alone.
        $this->asAdmin()->patchJson('/api/v1/admin/movies/'.$movie->id, ['featured' => true])
            ->assertOk()
            ->assertJsonPath('data.featured', true);

        $this->assertSame(2, $movie->videoSources()->count());
    }

    public function test_updating_one_movie_cannot_steal_another_slug(): void
    {
        $first = Movie::factory()->create(['slug' => 'first-slug']);
        $second = Movie::factory()->create(['slug' => 'second-slug']);

        $this->asAdmin()->putJson('/api/v1/admin/movies/'.$second->id, ['slug' => $first->slug])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['slug']);

        // The same payload for the owner is valid because uniqueness ignores itself.
        $this->asAdmin()->putJson('/api/v1/admin/movies/'.$first->id, ['slug' => $first->slug])
            ->assertOk();
    }

    public function test_deleting_a_movie_removes_its_sources_and_pivots(): void
    {
        $movie = Movie::factory()->create();
        $genre = Genre::factory()->create();
        $movie->genres()->attach($genre);
        VideoSource::factory()->for($movie)->create();

        $this->asAdmin()->deleteJson('/api/v1/admin/movies/'.$movie->id)
            ->assertOk()
            ->assertJson(['status' => 'deleted']);

        $this->assertDatabaseMissing('movies', ['id' => $movie->id]);
        $this->assertSame(0, VideoSource::query()->count());
        $this->assertSame(0, DB::table('movie_genre')->count());
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'movie.deleted', 'target_id' => $movie->id]);
    }

    public function test_audit_trail_records_every_mutation_without_copying_stream_locations(): void
    {
        $response = $this->asAdmin()->postJson('/api/v1/admin/movies', $this->moviePayload());
        $id = $response->json('data.id');

        $this->asAdmin()->putJson('/api/v1/admin/movies/'.$id, ['title' => 'Renamed Title'])->assertOk();
        $this->asAdmin()->deleteJson('/api/v1/admin/movies/'.$id)->assertOk();

        $logs = AdminAuditLog::query()->orderBy('id')->get();

        $this->assertSame(['movie.created', 'movie.updated', 'movie.deleted'], $logs->pluck('action')->all());
        $this->assertSame(['title'], $logs[1]->properties['changed']);
        $this->assertSame('Renamed Title', $logs[1]->properties['after']['title']);
        $this->assertStringNotContainsString('m3u8', json_encode($logs->map->properties->all()));
        $this->assertStringNotContainsString('m3u8', json_encode($logs->map->description->all()));
    }

    public function test_unauthenticated_writes_are_refused(): void
    {
        $this->postJson('/api/v1/admin/movies', $this->moviePayload())->assertStatus(401);
    }

    public function test_admin_catalog_lists_drafts_and_supports_filters(): void
    {
        $published = Movie::factory()->create();
        $draft = Movie::factory()->draft()->create();

        $this->asAdmin()->getJson('/api/v1/admin/movies')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->asAdmin()->getJson('/api/v1/admin/movies?published=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $draft->id);

        $this->asAdmin()->getJson('/api/v1/admin/movies?published=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $published->id);

        $this->asAdmin()->getJson('/api/v1/admin/movies/'.$draft->id)
            ->assertOk()
            ->assertJsonPath('data.published', false);
    }

    public function test_audit_log_endpoint_is_admin_only(): void
    {
        AdminAuditLog::create([
            'user_id' => User::query()->value('id'),
            'action' => 'movie.created',
            'description' => 'Seeded for the test',
        ]);

        $this->asAdmin()->getJson('/api/v1/admin/audit-logs')
            ->assertOk()
            ->assertJsonPath('data.0.action', 'movie.created')
            ->assertJsonPath('data.0.actor.email', User::query()->value('email'));

        $this->asGuest();

        $this->getJson('/api/v1/admin/audit-logs')->assertStatus(401);
    }

    public function test_failed_source_replacement_rolls_back_metadata_and_existing_sources(): void
    {
        $movie = Movie::factory()->create(['title' => 'Original']);
        $source = VideoSource::factory()->for($movie)->create();
        VideoSource::creating(function () {
            throw new \RuntimeException('Simulated source persistence failure');
        });

        try {
            $this->withoutExceptionHandling();
            $this->asAdmin()->patchJson('/api/v1/admin/movies/'.$movie->id, [
                'title' => 'Changed',
                'sources' => [$this->source()],
            ]);
            $this->fail('Expected source write to fail');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated source persistence failure', $exception->getMessage());
        } finally {
            VideoSource::flushEventListeners();
        }

        $this->assertSame('Original', $movie->fresh()->title);
        $this->assertDatabaseHas('video_sources', ['id' => $source->id]);
        $this->assertDatabaseMissing('admin_audit_logs', ['action' => 'movie.updated']);
    }

    public function test_dashboard_is_admin_only_and_returns_catalog_counts(): void
    {
        Movie::factory()->create(['published' => true, 'featured' => true]);
        Movie::factory()->draft()->create(['featured' => false]);

        $this->asAdmin()->getJson('/api/v1/admin/dashboard')->assertOk()
            ->assertJsonPath('data.movies.total', 2)
            ->assertJsonPath('data.movies.published', 1)
            ->assertJsonPath('data.movies.draft', 1)
            ->assertJsonPath('data.movies.featured', 1);

        $this->asGuest();
        $this->getJson('/api/v1/admin/dashboard')->assertUnauthorized();
        $viewer = User::factory()->subscriber()->create();
        $this->withHeaders($this->bearer($viewer->createToken('web')->plainTextToken))
            ->getJson('/api/v1/admin/dashboard')->assertForbidden();
    }

    /**
     * withHeaders() persists for the rest of the test and Sanctum memoises the
     * resolved user, so a follow-up request is only anonymous once both are reset.
     */
    private function asGuest(): void
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
    }
}
