<?php
require __DIR__.'/../../services/api/vendor/autoload.php';
if (getenv('DB_DATABASE') !== '/tmp/hyperline-cdn-browser.sqlite' || getenv('APP_ENV') !== 'testing') {
    throw new RuntimeException('Dedicated CDN test database required.');
}
$app = require __DIR__.'/../../services/api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$user = App\Models\User::firstOrCreate(['email'=>'viewer@hyperline.test'], ['name'=>'CDN test viewer', 'password'=>'TestPassword!2026']);
$registry = [];
for ($i=1; $i<=2; $i++) {
    $movie=App\Models\Movie::firstOrCreate(['slug'=>'protected-test-'.$i], ['title'=>'Protected test '.$i,'published'=>true,'published_at'=>now(),'featured'=>true]);
    $source=$movie->videoSources()->firstOrCreate(['label'=>'main'], ['kind'=>'hls','url'=>'https://cdn.goldendragon2000.com/vod/approved'.$i.'/master.m3u8','enabled'=>true,'is_default'=>true]);
    $registry[$source->id]=['movie_id'=>$movie->id,'origin_directory'=>'/vod/approved'.$i.'/','manifest'=>'master.m3u8'];
}
file_put_contents(getenv('OTT_NGINX_ASSETS_FILE'), json_encode($registry));
