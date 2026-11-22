<?php

namespace Tests\Feature;

use App\Models\Genre;
use App\Models\Movie;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ApiHelpers;
use Tests\TestCase;

class GenreManagementTest extends TestCase
{
    use ApiHelpers, RefreshDatabase;

    private function admin(): static
    {
        return $this->withHeaders($this->bearer($this->adminToken(User::factory()->administrator()->create())));
    }

    public function test_genre_routes_require_an_administrator(): void
    {
        $this->getJson('/api/v1/admin/genres')->assertUnauthorized();
        $this->postJson('/api/v1/admin/genres', [])->assertUnauthorized();
        $genre = Genre::factory()->create();
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->getJson('/api/v1/admin/genres')->assertForbidden();
        $this->postJson('/api/v1/admin/genres', [])->assertForbidden();
        $this->patchJson('/api/v1/admin/genres/'.$genre->id, [])->assertForbidden();
        $this->deleteJson('/api/v1/admin/genres/'.$genre->id)->assertForbidden();
        $this->patchJson('/api/v1/admin/homepage', [])->assertForbidden();
    }

    public function test_bilingual_crud_order_unique_slug_disable_and_audit(): void
    {
        $this->admin();
        $id = $this->postJson('/api/v1/admin/genres', ['name' => 'Action', 'name_my' => 'အက်ရှင်', 'slug' => 'action', 'sort_order' => 10])
            ->assertCreated()->assertJsonPath('data.name_my', 'အက်ရှင်')->assertJsonPath('data.enabled', true)->json('data.id');
        $this->postJson('/api/v1/admin/genres', ['name' => 'Duplicate', 'slug' => 'action'])->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->patchJson("/api/v1/admin/genres/$id", ['slug' => '../bad'])->assertUnprocessable();
        $this->patchJson("/api/v1/admin/genres/$id", ['sort_order' => -1])->assertUnprocessable();
        $this->patchJson("/api/v1/admin/genres/$id", ['enabled' => false, 'name' => 'Action edited'])->assertOk();
        $this->getJson('/api/v1/genres')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/admin/genres')->assertJsonCount(1, 'data')->assertJsonPath('data.0.movies_count', 0)->assertJsonPath('data.0.series_count', 0);
        $this->deleteJson("/api/v1/admin/genres/$id")->assertNoContent();
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'genre.created']);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'genre.updated']);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'genre.deleted']);
    }

    public function test_assigned_genres_cannot_be_deleted_and_disabling_preserves_both_pivots(): void
    {
        $this->admin();
        $genres = Genre::factory()->count(2)->create();
        $movie = Movie::factory()->create(['published' => false]);
        $series = Series::factory()->create(['published' => false]);
        $movie->genres()->sync($genres->modelKeys());
        $series->genres()->sync($genres->modelKeys());
        $id = $genres[0]->id;
        $this->deleteJson("/api/v1/admin/genres/$id")->assertStatus(409);
        $this->patchJson("/api/v1/admin/genres/$id", ['enabled' => false])->assertOk();
        $this->assertCount(2, $movie->fresh()->genres);
        $this->assertCount(2, $series->fresh()->genres);
        $this->getJson('/api/v1/admin/genres')->assertJsonPath('data.0.movies_count', 1)->assertJsonPath('data.0.series_count', 1);
        $movie->genres()->detach();
        $this->deleteJson("/api/v1/admin/genres/$id")->assertStatus(409);
    }

    public function test_series_and_movie_admin_assignments_accept_multiple_genres(): void
    {
        $this->admin();
        $genres = Genre::factory()->count(2)->create();
        $slugs = $genres->pluck('slug')->all();
        $movie = Movie::factory()->create(['published' => false]);
        $series = Series::factory()->create(['published' => false]);
        $this->patchJson('/api/v1/admin/movies/'.$movie->id, ['genres' => $slugs])->assertOk()->assertJsonCount(2, 'data.genres');
        $this->patchJson('/api/v1/admin/series/'.$series->id, ['genres' => $slugs])->assertOk()->assertJsonCount(2, 'data.genres');
        $this->getJson('/api/v1/admin/series')->assertJsonCount(2, 'data.0.genres');
        $this->assertFalse($movie->fresh()->published);
        $this->assertFalse($series->fresh()->published);
    }

    public function test_suggestions_are_idempotent_and_do_not_overwrite_editorial_data(): void
    {
        Genre::factory()->create(['slug' => 'action', 'name' => 'Editorial action', 'enabled' => false]);
        $this->artisan('ott:seed-suggested-genres')->assertSuccessful();
        $this->artisan('ott:seed-suggested-genres')->assertSuccessful();
        $this->assertDatabaseCount('genres', 12);
        $this->assertDatabaseHas('genres', ['slug' => 'action', 'name' => 'Editorial action', 'enabled' => false]);
        $this->assertDatabaseCount('movie_genre', 0);
        $this->assertDatabaseCount('series_genre', 0);
    }

    public function test_public_genres_sort_and_search_myanmar_without_exposing_disabled_genres(): void
    {
        $action = Genre::factory()->create(['name' => 'Action', 'name_my' => 'အက်ရှင်', 'sort_order' => 10]);
        $first = Genre::factory()->create(['name' => 'Drama', 'sort_order' => 1]);
        Genre::factory()->create(['name' => 'Hidden', 'name_my' => 'အက်ရှင်', 'enabled' => false]);
        $this->getJson('/api/v1/genres')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $first->id);
        $this->getJson('/api/v1/search?q='.urlencode('အက်ရှင်'))->assertOk()->assertJsonCount(1, 'data.genres')->assertJsonPath('data.genres.0.id', $action->id);
    }

    public function test_suggested_seed_refuses_production(): void
    {
        $this->app['env'] = 'production';
        $this->artisan('ott:seed-suggested-genres')->assertFailed();
        $this->assertDatabaseCount('genres', 0);
    }

    public function test_homepage_order_is_validated_and_does_not_change_publication(): void
    {
        $this->admin();
        $this->patchJson('/api/v1/admin/homepage', ['sections' => ['latest', 'latest']])->assertUnprocessable();
        $sections = ['latest', 'trending', 'genres', 'continue', 'series', 'vertical', 'featured'];
        $this->patchJson('/api/v1/admin/homepage', ['sections' => $sections])->assertOk()->assertJsonPath('data.sections', $sections);
        $this->getJson('/api/v1/home')->assertOk()->assertJsonPath('data.section_order', $sections);
    }
}
