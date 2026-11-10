<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\PlaybackSession;
use App\Models\User;
use App\Models\VideoSource;
use App\Support\Contracts\PlaybackUrlSigner;
use App\Support\Playback\SignedPlaybackUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SignedPlaybackTest extends TestCase
{
    use RefreshDatabase;

    private function play(): TestResponse
    {
        $movie = Movie::factory()->create();
        VideoSource::factory()->for($movie)->create();
        $user = User::factory()->create();

        return $this->withToken($user->createToken('web')->plainTextToken)
            ->postJson('/api/v1/playback/sessions', ['movie_id' => $movie->id]);
    }

    public function test_missing_signer_denies_playback_without_leaking_the_origin(): void
    {
        config(['ott.playback.signed_urls' => true, 'ott.playback.signer' => null]);
        $response = $this->play()->assertStatus(503);
        $this->assertStringNotContainsString('m3u8', $response->getContent());
        $this->assertDatabaseCount('playback_sessions', 0);
    }

    public function test_mock_signer_returns_session_scoped_url_and_expiry(): void
    {
        config(['ott.playback.signed_urls' => true]);
        $this->app->instance(PlaybackUrlSigner::class, new class implements PlaybackUrlSigner
        {
            public function sign(VideoSource $source, PlaybackSession $session): SignedPlaybackUrl
            {
                return new SignedPlaybackUrl('https://signed.example.test/'.$session->public_id.'/master.m3u8', $session->expires_at->copy()->subSeconds(10));
            }
        });
        $response = $this->play()->assertCreated()->assertJsonPath('data.signature_required', true);
        $session = PlaybackSession::firstOrFail();
        $this->assertStringContainsString($session->public_id, $response->json('data.url'));
        $this->assertStringNotContainsString($session->videoSource->url, $response->getContent());
        $this->assertTrue($session->expires_at->isFuture());
    }

    public function test_production_cannot_disable_signing(): void
    {
        $this->app->instance('env', 'production');
        config(['ott.playback.signed_urls' => false, 'ott.playback.signer' => null]);
        $this->play()->assertStatus(503);
        $this->assertDatabaseCount('playback_sessions', 0);
    }

    public static function invalidAuthorisations(): array
    {
        return [['origin'], ['expired'], ['extended']];
    }

    #[DataProvider('invalidAuthorisations')]
    public function test_signer_cannot_return_invalid_authorisation(string $case): void
    {
        config(['ott.playback.signed_urls' => true]);
        $this->app->instance(PlaybackUrlSigner::class, new class($case) implements PlaybackUrlSigner
        {
            public function __construct(private string $case) {}

            public function sign(VideoSource $source, PlaybackSession $session): SignedPlaybackUrl
            {
                return new SignedPlaybackUrl(
                    $this->case === 'origin' ? $source->url : 'https://signed.example.test/master.m3u8?test=authorization',
                    match ($this->case) {
                        'expired' => now()->subMinute(),
                        'extended' => $session->expires_at->copy()->addHour(),
                        default => $session->expires_at,
                    },
                );
            }
        });
        $this->play()->assertStatus(503);
        $this->assertDatabaseCount('playback_sessions', 0);
    }
}
