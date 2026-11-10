<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 step 4 of the documented migration strategy: the "exactly one typed
 * owner" invariant for sources and sessions becomes a real database CHECK on
 * both supported drivers, instead of the application-layer claim the Phase 1
 * series migration could only make.
 *
 * Rows already satisfy the invariant (movie rows have movie_id, episode rows
 * have episode_id), but per the strategy the migration verifies a restored
 * database and refuses to install constraints over ambiguous rows rather than
 * repairing or deleting them silently. SQLite gains the CHECK through table
 * rebuilds because it has no ALTER ADD CONSTRAINT; the twelve-step rebuild
 * runs under PRAGMA defer_foreign_keys so the parent/child pair
 * (video_sources <- playback_sessions) can be swapped inside the migration
 * transaction, with constraints re-verified at commit.
 *
 * No columns, indexes or data change shape; down() only removes the checks.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->guardOwnership();

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA defer_foreign_keys = ON');
            $this->rebuildSqlite('video_sources', $this->videoSourcesDdl(withCheck: true), $this->videoSourcesIndexes());
            $this->rebuildSqlite('playback_sessions', $this->playbackSessionsDdl(withCheck: true), $this->playbackSessionsIndexes());
        } else {
            DB::statement('ALTER TABLE video_sources ADD CONSTRAINT video_sources_owner_xor CHECK ((movie_id IS NULL) <> (episode_id IS NULL))');
            DB::statement('ALTER TABLE playback_sessions ADD CONSTRAINT playback_sessions_owner_xor CHECK ((movie_id IS NULL) <> (episode_id IS NULL))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA defer_foreign_keys = ON');
            $this->rebuildSqlite('video_sources', $this->videoSourcesDdl(withCheck: false), $this->videoSourcesIndexes());
            $this->rebuildSqlite('playback_sessions', $this->playbackSessionsDdl(withCheck: false), $this->playbackSessionsIndexes());
        } else {
            DB::statement('ALTER TABLE video_sources DROP CONSTRAINT video_sources_owner_xor');
            DB::statement('ALTER TABLE playback_sessions DROP CONSTRAINT playback_sessions_owner_xor');
        }
    }

    private function guardOwnership(): void
    {
        foreach (['video_sources', 'playback_sessions'] as $table) {
            $ownerless = DB::table($table)->whereNull('movie_id')->whereNull('episode_id')->count();
            $dual = DB::table($table)->whereNotNull('movie_id')->whereNotNull('episode_id')->count();
            if ($ownerless > 0 || $dual > 0) {
                throw new RuntimeException(
                    "Refusing to install ownership checks: {$table} has {$ownerless} ownerless and {$dual} dual-owner row(s). Repair them, then re-run migrations.",
                );
            }
        }
    }

    /**
     * @param  list<string>  $indexes
     */
    private function rebuildSqlite(string $table, string $ddl, array $indexes): void
    {
        // Column lists are explicit on both sides: SELECT * would map by
        // position, and SQLite tables rebuilt by earlier migrations may carry
        // columns in a different order than the DDL below.
        $columns = $table === 'video_sources'
            ? 'id, movie_id, episode_id, label, kind, url, quality, language, is_default, enabled, created_at, updated_at'
            : 'id, public_id, user_id, movie_id, episode_id, video_source_id, status, started_at, expires_at, finished_at, ip_address, user_agent, created_at, updated_at';
        DB::statement($ddl);
        DB::statement("INSERT INTO {$table}__new ({$columns}) SELECT {$columns} FROM {$table}");
        DB::statement("DROP TABLE {$table}");
        DB::statement("ALTER TABLE {$table}__new RENAME TO {$table}");
        foreach ($indexes as $index) {
            DB::statement($index);
        }
    }

    private function videoSourcesDdl(bool $withCheck): string
    {
        $check = $withCheck ? ",\n    check ((movie_id is null) <> (episode_id is null))" : '';

        return <<<SQL
            CREATE TABLE video_sources__new (
                id integer primary key autoincrement not null,
                movie_id bigint null,
                episode_id bigint null,
                label varchar(60) not null default 'main',
                kind varchar(20) not null default 'hls',
                url varchar(2048) not null,
                quality varchar(20) null,
                language varchar(32) null,
                is_default tinyint(1) not null default '0',
                enabled tinyint(1) not null default '1',
                created_at datetime null,
                updated_at datetime null,
                foreign key (movie_id) references movies (id) on delete cascade,
                foreign key (episode_id) references series_episodes (id) on delete cascade{$check}
            )
            SQL;
    }

    /** @return list<string> */
    private function videoSourcesIndexes(): array
    {
        return [
            'CREATE UNIQUE INDEX video_sources_movie_id_label_unique ON video_sources (movie_id, label)',
            'CREATE UNIQUE INDEX video_sources_episode_id_label_unique ON video_sources (episode_id, label)',
            'CREATE INDEX video_sources_movie_id_enabled_index ON video_sources (movie_id, enabled)',
            'CREATE INDEX video_sources_episode_id_enabled_index ON video_sources (episode_id, enabled)',
        ];
    }

    private function playbackSessionsDdl(bool $withCheck): string
    {
        $check = $withCheck ? ",\n    check ((movie_id is null) <> (episode_id is null))" : '';

        return <<<SQL
            CREATE TABLE playback_sessions__new (
                id integer primary key autoincrement not null,
                public_id varchar(36) not null,
                user_id bigint not null,
                movie_id bigint null,
                episode_id bigint null,
                video_source_id bigint null,
                status varchar(16) not null default 'active',
                started_at datetime not null,
                expires_at datetime not null,
                finished_at datetime null,
                ip_address varchar(45) null,
                user_agent varchar(255) null,
                created_at datetime null,
                updated_at datetime null,
                foreign key (user_id) references users (id) on delete cascade,
                foreign key (movie_id) references movies (id) on delete cascade,
                foreign key (episode_id) references series_episodes (id) on delete cascade,
                foreign key (video_source_id) references video_sources (id) on delete set null{$check}
            )
            SQL;
    }

    /** @return list<string> */
    private function playbackSessionsIndexes(): array
    {
        return [
            'CREATE UNIQUE INDEX playback_sessions_public_id_unique ON playback_sessions (public_id)',
            'CREATE INDEX playback_sessions_status_index ON playback_sessions (status)',
            'CREATE INDEX playback_sessions_expires_at_index ON playback_sessions (expires_at)',
            'CREATE INDEX playback_sessions_user_id_movie_id_index ON playback_sessions (user_id, movie_id)',
            'CREATE INDEX playback_sessions_movie_id_expires_at_index ON playback_sessions (movie_id, expires_at)',
            'CREATE INDEX playback_sessions_user_id_episode_id_index ON playback_sessions (user_id, episode_id)',
            'CREATE INDEX playback_sessions_episode_id_expires_at_index ON playback_sessions (episode_id, expires_at)',
        ];
    }
};
