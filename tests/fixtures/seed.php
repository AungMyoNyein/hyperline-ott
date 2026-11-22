<?php

use App\Models\Genre;
use App\Models\Movie;
use App\Models\Role;
use App\Models\Season;
use App\Models\Series;
use App\Models\SeriesEpisode;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

// Only the disposable browser-test database may be seeded by this script.
require __DIR__.'/../../services/api/vendor/autoload.php';
$fixtureDatabase = (string) getenv('DB_DATABASE');
if (!preg_match('~^/tmp/hyperline-browser-[A-Za-z0-9]{6}\.sqlite$~D', $fixtureDatabase)
    || realpath($fixtureDatabase) !== $fixtureDatabase
    || is_link($fixtureDatabase)
    || getenv('DB_CONNECTION') !== 'sqlite'
    || getenv('APP_ENV') !== 'testing') {
    throw new RuntimeException('Browser fixtures require the dedicated temporary database.');
}
$app = require __DIR__.'/../../services/api/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$admin = User::firstOrCreate(['email' => 'admin@hyperline.test'], ['name' => 'Test administrator', 'password' => 'TestPassword!2026']);
$role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Administrator']);
$admin->roles()->syncWithoutDetaching([$role->id]);
$viewer = User::firstOrCreate(['email' => 'viewer@hyperline.test'], ['name' => 'Test viewer', 'password' => 'TestPassword!2026']);
$genre = Genre::firstOrCreate(['slug' => 'test-patterns'], ['name' => 'Test patterns']);
$movie = Movie::firstOrCreate(['slug' => 'local-test-pattern'], ['title' => 'Local test pattern', 'synopsis' => 'An original generated color pattern for authorized playback tests.', 'published' => true, 'featured' => true, 'published_at' => now()]);
$movie->genres()->syncWithoutDetaching([$genre->id]);
$movie->videoSources()->firstOrCreate(['label' => 'main'], ['kind' => 'hls', 'url' => 'https://127.0.0.1:18084/master.m3u8', 'enabled' => true, 'is_default' => true]);
Movie::firstOrCreate(['slug' => 'hidden-test-draft'], ['title' => 'Hidden test draft', 'published' => false]);
$dashMovie = Movie::firstOrCreate(['slug' => 'local-dash-pattern'], ['title' => 'Local DASH pattern', 'synopsis' => 'A generated DASH transport fixture for dash.js playback tests.', 'published' => true, 'featured' => false, 'published_at' => now()]);
$dashMovie->videoSources()->firstOrCreate(['label' => 'main'], ['kind' => 'dash', 'url' => 'https://127.0.0.1:18084/dash/manifest.mpd', 'enabled' => true, 'is_default' => true]);
$youtubeMovie = Movie::firstOrCreate(['slug' => 'local-youtube-pattern'], ['title' => 'Local YouTube pattern', 'synopsis' => 'A YouTube embed fixture; the link is a public video, never a HyperLine origin.', 'published' => true, 'featured' => false, 'published_at' => now()]);
$youtubeMovie->videoSources()->firstOrCreate(['label' => 'main'], ['kind' => 'youtube', 'url' => 'https://www.youtube.com/watch?v=M7lc1UVf-VE', 'enabled' => true, 'is_default' => true]);
$youtubeSeries = Series::create(['title' => 'YouTube sample series', 'slug' => 'youtube-sample-series', 'category' => 'vertical', 'published' => true, 'published_at' => now()]);
$youtubeSeason = Season::create(['series_id' => $youtubeSeries->id, 'season_number' => 1, 'published' => true]);
foreach ([1 => 'youtube', 2 => 'youtube', 3 => 'dash', 4 => 'hls'] as $number => $kind) {
    $episode = SeriesEpisode::create(['series_id' => $youtubeSeries->id, 'season_id' => $youtubeSeason->id, 'episode_number' => $number, 'title' => 'Sample '.$number, 'published' => true]);
    $url = match ($kind) {
        'youtube' => 'https://youtu.be/M7lc1UVf-VE', 'dash' => 'https://127.0.0.1:18084/dash/manifest.mpd', default => 'https://127.0.0.1:18084/master.m3u8'
    };
    $episode->videoSources()->create(['label' => 'main', 'kind' => $kind, 'url' => $url, 'is_default' => true, 'enabled' => true]);
}
$series = Series::firstOrCreate(['slug' => 'local-vertical-series'], ['title' => 'Local vertical series', 'synopsis' => 'A generated vertical fixture series for authorized episode playback tests.', 'category' => 'vertical', 'language' => 'Chinese', 'published' => true, 'featured' => false, 'published_at' => now()]);
$season = Season::firstOrCreate(['series_id' => $series->id, 'season_number' => 1], ['published' => true]);
foreach ([1, 2, 3] as $number) {
    $episode = SeriesEpisode::firstOrCreate(['season_id' => $season->id, 'episode_number' => $number], ['series_id' => $series->id, 'title' => "Fixture episode {$number}", 'duration_seconds' => 480, 'published' => true]);
    $episode->videoSources()->firstOrCreate(['label' => 'main'], ['kind' => 'hls', 'url' => 'https://127.0.0.1:18084/master.m3u8', 'enabled' => true, 'is_default' => true]);
}
$draftEpisode = SeriesEpisode::firstOrCreate(['season_id' => $season->id, 'episode_number' => 4], ['series_id' => $series->id, 'title' => 'Hidden fixture episode', 'published' => false]);
Series::firstOrCreate(['slug' => 'hidden-series-draft'], ['title' => 'Hidden series draft', 'published' => false]);
Season::firstOrCreate(['series_id' => Series::where('slug', 'hidden-series-draft')->value('id'), 'season_number' => 1], ['published' => true]);
