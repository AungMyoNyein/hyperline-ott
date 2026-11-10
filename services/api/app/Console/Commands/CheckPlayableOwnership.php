<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration-strategy step 1: inventory the applied migrations and detect
 * ownerless / dual-owner / mismatched-ownership rows in the playable-owner
 * tables BEFORE constraint migrations run against a restored database. The
 * constraint migrations refuse to install over ambiguous rows; this command is
 * how an operator finds and reviews them. It is strictly read-only and never
 * repairs or deletes anything.
 */
class CheckPlayableOwnership extends Command
{
    protected $signature = 'hyperline:check-playable-ownership
        {--json : Emit a machine-readable JSON report}';

    protected $description = 'Read-only audit of playable ownership integrity (video_sources, playback_sessions, watch_progress, series_episodes)';

    public function handle(): int
    {
        $report = [
            'migrations_applied' => DB::table('migrations')->count(),
            'violations' => [],
        ];

        foreach (['video_sources', 'playback_sessions', 'watch_progress'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'episode_id')) {
                $report['violations'][$table] = 'skipped: table or episode_id column not present yet';

                continue;
            }

            $ownerless = DB::table($table)->whereNull('movie_id')->whereNull('episode_id')->count();
            $dual = DB::table($table)->whereNotNull('movie_id')->whereNotNull('episode_id')->count();
            $report['violations'][$table] = ['ownerless' => $ownerless, 'dual_owner' => $dual];
        }

        if (Schema::hasTable('series_episodes') && Schema::hasColumn('series_episodes', 'season_id')) {
            $missingSeason = DB::table('series_episodes')->whereNull('season_id')->count();
            $mismatched = DB::table('series_episodes as e')
                ->join('seasons as s', 's.id', '=', 'e.season_id')
                ->whereColumn('s.series_id', '!=', 'e.series_id')
                ->count();
            $report['violations']['series_episodes'] = [
                'missing_season' => $missingSeason,
                'season_series_mismatch' => $mismatched,
            ];
        } else {
            $report['violations']['series_episodes'] = 'skipped: season layer not migrated yet';
        }

        $total = collect($report['violations'])
            ->filter(fn ($entry) => is_array($entry))
            ->flatMap(fn ($entry) => $entry)
            ->sum();

        $report['ok'] = $total === 0;

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPES_SLASHES));
        } else {
            foreach ($report['violations'] as $table => $entry) {
                $this->line(is_array($entry)
                    ? sprintf('%s: %s', $table, json_encode($entry))
                    : sprintf('%s: %s', $table, $entry));
            }
            $report['ok']
                ? $this->info('Ownership integrity OK.')
                : $this->error("Found {$total} violation(s). Repair them before installing ownership constraints.");
        }

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
