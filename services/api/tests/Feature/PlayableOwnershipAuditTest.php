<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\VideoSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PlayableOwnershipAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_json_audit_is_valid_json_and_succeeds_for_an_empty_migrated_database(): void
    {
        $exit = Artisan::call('hyperline:check-playable-ownership', ['--json' => true]);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertTrue($report['ok']);
        $this->assertSame(13, $report['migrations_applied']);
        $this->assertSame(['ownerless' => 0, 'dual_owner' => 0], $report['violations']['video_sources']);
        $this->assertSame(['missing_season' => 0, 'season_series_mismatch' => 0], $report['violations']['series_episodes']);
    }

    public function test_json_and_text_audits_preserve_existing_playable_rows(): void
    {
        $movie = Movie::factory()->create();
        $source = VideoSource::factory()->for($movie)->create();
        $before = $source->fresh()->getRawOriginal();

        $this->assertSame(0, Artisan::call('hyperline:check-playable-ownership', ['--json' => true]));
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($report['ok']);
        $this->assertSame(0, Artisan::call('hyperline:check-playable-ownership'));
        $this->assertStringContainsString('Ownership integrity OK.', Artisan::output());
        $this->assertSame($before, $source->fresh()->getRawOriginal());
        $this->assertDatabaseCount('video_sources', 1);
    }
}
