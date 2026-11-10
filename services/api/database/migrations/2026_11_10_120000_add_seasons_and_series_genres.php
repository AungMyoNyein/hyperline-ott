<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 step 2 of the documented migration strategy: insert the Season layer
 * between Series and Episodes without touching existing rows' identities.
 *
 * Every existing series gains a published "season 1" and every existing
 * episode is attached to it, so the flat Phase 1 hierarchy keeps its meaning.
 * Episode uniqueness moves from (series_id, episode_number) to
 * (season_id, episode_number) only after the backfill is verified complete.
 *
 * episodes.series_id is retained deliberately: the composite foreign key
 * (series_id, season_id) -> seasons(series_id, id) makes it impossible at the
 * database level for an episode's season to belong to a different series, so
 * "mismatched nested identifiers" are rejected by the DB, not just by request
 * validation. Season number 0 is reserved for specials (see Season model).
 *
 * series_genre mirrors movie_genre: an additive pivot so series can carry
 * genres like movies do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('series_id')->constrained('series')->cascadeOnDelete();
            $table->unsignedSmallInteger('season_number');
            $table->string('title', 255)->nullable();
            $table->text('synopsis')->nullable();
            $table->string('poster_url', 1024)->nullable();
            $table->string('backdrop_url', 1024)->nullable();
            $table->boolean('published')->default(true);
            $table->timestamps();

            $table->unique(['series_id', 'season_number']);
            // Parent key for the episodes (series_id, season_id) composite FK.
            $table->unique(['series_id', 'id']);
            $table->index(['series_id', 'published']);
        });

        Schema::create('series_genre', function (Blueprint $table) {
            $table->foreignId('series_id')->constrained('series')->cascadeOnDelete();
            $table->foreignId('genre_id')->constrained()->cascadeOnDelete();
            $table->primary(['series_id', 'genre_id']);
            $table->index('genre_id');
        });

        $now = now()->toDateTimeString();
        DB::statement(
            'INSERT INTO seasons (series_id, season_number, published, created_at, updated_at) SELECT id, 1, TRUE, ?, ? FROM series',
            [$now, $now],
        );

        Schema::table('series_episodes', function (Blueprint $table) {
            $table->foreignId('season_id')->nullable()->after('series_id')
                ->constrained('seasons')->cascadeOnDelete();
        });

        DB::statement(
            'UPDATE series_episodes SET season_id = (SELECT s.id FROM seasons s WHERE s.series_id = series_episodes.series_id AND s.season_number = 1)',
        );

        // Step 1 of the strategy: refuse to install constraints until every row
        // is accounted for. Never silently delete or re-key ambiguous rows.
        $orphans = DB::table('series_episodes')->whereNull('season_id')->count();
        if ($orphans > 0) {
            throw new RuntimeException(
                "Refusing to install season ownership: {$orphans} episode row(s) could not be mapped to a season. Repair them, then re-run migrations.",
            );
        }

        Schema::table('series_episodes', function (Blueprint $table) {
            $table->dropUnique(['series_id', 'episode_number']);
        });

        Schema::table('series_episodes', function (Blueprint $table) {
            $table->unsignedBigInteger('season_id')->nullable(false)->change();
        });

        Schema::table('series_episodes', function (Blueprint $table) {
            $table->unique(['season_id', 'episode_number']);
            $table->foreign(['series_id', 'season_id'])
                ->references(['series_id', 'id'])->on('seasons')->cascadeOnDelete();
            $table->index(['season_id', 'published', 'episode_number']);
        });
    }

    /**
     * Forward-compatible rollback: collapsing seasons back into flat per-series
     * numbering is only safe while the tree is still in the backfilled
     * "season 1 per series" state. Anything richer must be unwound explicitly
     * by an operator, so this refuses instead of destroying episode identities.
     */
    public function down(): void
    {
        $extraSeasons = DB::table('seasons')->where('season_number', '!=', 1)->count();
        $collisions = DB::table('series_episodes')
            ->select('series_id', 'episode_number')
            ->groupBy('series_id', 'episode_number')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        if ($extraSeasons > 0 || $collisions > 0) {
            throw new RuntimeException(
                'Refusing to roll back seasons: seasons beyond season 1 exist or episode numbers would collide per series. Remove the newer season structure first.',
            );
        }

        Schema::table('series_episodes', function (Blueprint $table) {
            $table->dropForeign(['series_id', 'season_id']);
            $table->dropUnique(['season_id', 'episode_number']);
            $table->dropIndex(['season_id', 'published', 'episode_number']);
            $table->unique(['series_id', 'episode_number']);
        });

        Schema::table('series_episodes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('season_id');
        });

        Schema::dropIfExists('series_genre');
        Schema::dropIfExists('seasons');
    }
};
