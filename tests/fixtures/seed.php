<?php
// Only the disposable browser-test database may be seeded by this script.
require __DIR__.'/../../services/api/vendor/autoload.php';
if (getenv('DB_DATABASE') !== '/tmp/hyperline-browser.sqlite' || getenv('APP_ENV') !== 'testing') {
    throw new RuntimeException('Browser fixtures require the dedicated temporary database.');
}
$app = require __DIR__.'/../../services/api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$admin = App\Models\User::firstOrCreate(['email' => 'admin@hyperline.test'], ['name' => 'Test administrator', 'password' => 'TestPassword!2026']);
$role = App\Models\Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Administrator']);
$admin->roles()->syncWithoutDetaching([$role->id]);
$viewer = App\Models\User::firstOrCreate(['email' => 'viewer@hyperline.test'], ['name' => 'Test viewer', 'password' => 'TestPassword!2026']);
$genre = App\Models\Genre::firstOrCreate(['slug' => 'test-patterns'], ['name' => 'Test patterns']);
$movie = App\Models\Movie::firstOrCreate(['slug' => 'local-test-pattern'], ['title' => 'Local test pattern', 'synopsis' => 'An original generated color pattern for authorized playback tests.', 'published' => true, 'featured' => true, 'published_at' => now()]);
$movie->genres()->syncWithoutDetaching([$genre->id]);
$movie->videoSources()->firstOrCreate(['label' => 'main'], ['kind' => 'hls', 'url' => 'https://127.0.0.1:18084/master.m3u8', 'enabled' => true, 'is_default' => true]);
App\Models\Movie::firstOrCreate(['slug' => 'hidden-test-draft'], ['title' => 'Hidden test draft', 'published' => false]);
$dashMovie = App\Models\Movie::firstOrCreate(['slug' => 'local-dash-pattern'], ['title' => 'Local DASH pattern', 'synopsis' => 'A generated DASH transport fixture for dash.js playback tests.', 'published' => true, 'featured' => false, 'published_at' => now()]);
$dashMovie->videoSources()->firstOrCreate(['label' => 'main'], ['kind' => 'dash', 'url' => 'https://127.0.0.1:18084/dash/manifest.mpd', 'enabled' => true, 'is_default' => true]);
$youtubeMovie = App\Models\Movie::firstOrCreate(['slug' => 'local-youtube-pattern'], ['title' => 'Local YouTube pattern', 'synopsis' => 'A YouTube embed fixture; the link is a public video, never a HyperLine origin.', 'published' => true, 'featured' => false, 'published_at' => now()]);
$youtubeMovie->videoSources()->firstOrCreate(['label' => 'main'], ['kind' => 'youtube', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'enabled' => true, 'is_default' => true]);
$series = App\Models\Series::firstOrCreate(['slug' => 'local-vertical-series'], ['title' => 'Local vertical series', 'synopsis' => 'A generated vertical fixture series for authorized episode playback tests.', 'category' => 'vertical', 'language' => 'Chinese', 'published' => true, 'featured' => false, 'published_at' => now()]);
$season = App\Models\Season::firstOrCreate(['series_id' => $series->id, 'season_number' => 1], ['published' => true]);
foreach ([1, 2, 3] as $number) {
    $episode = App\Models\SeriesEpisode::firstOrCreate(['season_id' => $season->id, 'episode_number' => $number], ['series_id' => $series->id, 'title' => "Fixture episode {$number}", 'duration_seconds' => 480, 'published' => true]);
    $episode->videoSources()->firstOrCreate(['label' => 'main'], ['kind' => 'hls', 'url' => 'https://127.0.0.1:18084/master.m3u8', 'enabled' => true, 'is_default' => true]);
}
$draftEpisode = App\Models\SeriesEpisode::firstOrCreate(['season_id' => $season->id, 'episode_number' => 4], ['series_id' => $series->id, 'title' => 'Hidden fixture episode', 'published' => false]);
App\Models\Series::firstOrCreate(['slug' => 'hidden-series-draft'], ['title' => 'Hidden series draft', 'published' => false]);
App\Models\Season::firstOrCreate(['series_id' => App\Models\Series::where('slug', 'hidden-series-draft')->value('id'), 'season_number' => 1], ['published' => true]);
