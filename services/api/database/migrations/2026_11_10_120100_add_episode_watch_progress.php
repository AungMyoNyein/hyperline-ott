<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 step 3 of the documented migration strategy: episode-level watch
 * progress, additive to the movie-only Phase 1 table.
 *
 * A progress row is owned by exactly one playable: movie_id XOR episode_id.
 * The invariant is a real database CHECK on both supported drivers — SQLite
 * gains it through a table rebuild because SQLite has no ALTER ADD CONSTRAINT.
 * Per-owner uniqueness uses partial unique indexes, which PostgreSQL and
 * SQLite both support with identical syntax, so a user keeps at most one
 * progress row per movie and one per episode.
 *
 * Existing movie rows and the movie request/response contract are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('watch_progress', function (Blueprint $table) {
            $table->foreignId('episode_id')->nullable()->after('movie_id')
                ->constrained('series_episodes')->cascadeOnDelete();
            $table->foreignId('movie_id')->nullable()->change();
        });

        $ownerless = DB::table('watch_progress')->whereNull('movie_id')->whereNull('episode_id')->count();
        $dual = DB::table('watch_progress')->whereNotNull('movie_id')->whereNotNull('episode_id')->count();
        if ($ownerless > 0 || $dual > 0) {
            throw new RuntimeException(
                "Refusing to install ownership checks: watch_progress has {$ownerless} ownerless and {$dual} dual-owner row(s). Repair them, then re-run migrations.",
            );
        }

        Schema::table('watch_progress', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'movie_id']);
        });

        if (DB::getDriverName() === 'sqlite') {
            // The rebuild drops the table (and its indexes), so it recreates them.
            $this->rebuildSqliteWithCheck();
        } else {
            DB::statement('ALTER TABLE watch_progress ADD CONSTRAINT watch_progress_owner_xor CHECK ((movie_id IS NULL) <> (episode_id IS NULL))');
        }

        DB::statement('CREATE UNIQUE INDEX watch_progress_user_movie_unique ON watch_progress (user_id, movie_id) WHERE movie_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX watch_progress_user_episode_unique ON watch_progress (user_id, episode_id) WHERE episode_id IS NOT NULL');
    }

    /**
     * SQLite twelve-step rebuild with the CHECK baked into the definition.
     * defer_foreign_keys keeps the swap safe inside the migration transaction;
     * constraints are re-verified at commit against the renamed table.
     */
    private function rebuildSqliteWithCheck(): void
    {
        DB::statement('PRAGMA defer_foreign_keys = ON');
        DB::statement(<<<'SQL'
            CREATE TABLE watch_progress__new (
                id integer primary key autoincrement not null,
                user_id bigint not null,
                movie_id bigint null,
                episode_id bigint null,
                position_seconds integer not null default '0',
                duration_seconds integer null,
                completed tinyint(1) not null default '0',
                last_watched_at datetime null,
                created_at datetime null,
                updated_at datetime null,
                foreign key (user_id) references users (id) on delete cascade,
                foreign key (movie_id) references movies (id) on delete cascade,
                foreign key (episode_id) references series_episodes (id) on delete cascade,
                check ((movie_id is null) <> (episode_id is null))
            )
            SQL);
        DB::statement('INSERT INTO watch_progress__new (id, user_id, movie_id, episode_id, position_seconds, duration_seconds, completed, last_watched_at, created_at, updated_at) SELECT id, user_id, movie_id, episode_id, position_seconds, duration_seconds, completed, last_watched_at, created_at, updated_at FROM watch_progress');
        DB::statement('DROP TABLE watch_progress');
        DB::statement('ALTER TABLE watch_progress__new RENAME TO watch_progress');
        DB::statement('CREATE INDEX watch_progress_last_watched_at_index ON watch_progress (last_watched_at)');
        DB::statement('CREATE INDEX watch_progress_user_id_completed_index ON watch_progress (user_id, completed)');
    }
    /**
     * Forward-compatible rollback: episode history is real viewer data, so this
     * refuses while any episode-owned row exists instead of deleting it. Once
     * episode rows are gone the movie-only Phase 1 shape is restored exactly.
     */
    public function down(): void
    {
        $episodeRows = DB::table('watch_progress')->whereNotNull('episode_id')->count();
        if ($episodeRows > 0) {
            throw new RuntimeException(
                "Refusing to roll back: {$episodeRows} episode progress row(s) exist. Export or remove them explicitly first.",
            );
        }

        DB::statement('DROP INDEX IF EXISTS watch_progress_user_episode_unique');
        DB::statement('DROP INDEX IF EXISTS watch_progress_user_movie_unique');

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA defer_foreign_keys = ON');
            DB::statement(<<<'SQL'
                CREATE TABLE watch_progress__old (
                    id integer primary key autoincrement not null,
                    user_id bigint not null,
                    movie_id bigint not null,
                    position_seconds integer not null default '0',
                    duration_seconds integer null,
                    completed tinyint(1) not null default '0',
                    last_watched_at datetime null,
                    created_at datetime null,
                    updated_at datetime null,
                    foreign key (user_id) references users (id) on delete cascade,
                    foreign key (movie_id) references movies (id) on delete cascade
                )
                SQL);
            DB::statement('INSERT INTO watch_progress__old (id, user_id, movie_id, position_seconds, duration_seconds, completed, last_watched_at, created_at, updated_at) SELECT id, user_id, movie_id, position_seconds, duration_seconds, completed, last_watched_at, created_at, updated_at FROM watch_progress');
            DB::statement('DROP TABLE watch_progress');
            DB::statement('ALTER TABLE watch_progress__old RENAME TO watch_progress');
            DB::statement('CREATE UNIQUE INDEX watch_progress_user_id_movie_id_unique ON watch_progress (user_id, movie_id)');
            DB::statement('CREATE INDEX watch_progress_last_watched_at_index ON watch_progress (last_watched_at)');
            DB::statement('CREATE INDEX watch_progress_user_id_completed_index ON watch_progress (user_id, completed)');
        } else {
            DB::statement('ALTER TABLE watch_progress DROP CONSTRAINT watch_progress_owner_xor');
            Schema::table('watch_progress', function (Blueprint $table) {
                $table->dropConstrainedForeignId('episode_id');
                $table->foreignId('movie_id')->nullable(false)->change();
                $table->unique(['user_id', 'movie_id']);
            });
        }
    }
};
