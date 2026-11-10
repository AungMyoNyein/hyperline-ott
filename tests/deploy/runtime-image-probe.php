<?php
// Executed ONLY in an ephemeral --network none test container, no production mounts.
require '/var/www/html/vendor/autoload.php';
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
function runtimeProbeCheck(bool $condition, string $label): void {
    if (! $condition) { fwrite(STDERR, 'FAIL '.$label.PHP_EOL); exit(1); }
}
try {
    putenv('APP_KEY=base64:'.base64_encode(random_bytes(32)));
    touch('/tmp/hyperline-contract.sqlite');
    $app = require '/var/www/html/bootstrap/app.php';
    $console = $app->make(ConsoleKernel::class);$console->bootstrap();
    if (! getenv('GUARDED_ROLLBACK_PROBE')) { runtimeProbeCheck(extension_loaded('pcntl'), 'pcntl runtime extension'); }
    runtimeProbeCheck(extension_loaded('pdo_pgsql'), 'PostgreSQL runtime extension');
    runtimeProbeCheck(! is_file('/var/www/html/.env'), 'no local secrets in image');
    runtimeProbeCheck(! is_dir('/var/www/html/tests'), 'no dev tests/signers in production image');
    runtimeProbeCheck(! is_file('/var/www/html/database/database.sqlite'), 'no developer SQLite database in image');
    runtimeProbeCheck(! $app->configurationIsCached(), 'no stale config cache');
    runtimeProbeCheck($console->call('migrate', ['--force'=>true]) === 0, 'disposable schema');
    $user = App\Models\User::create(['name'=>'Image test','email'=>'fixture@hyperline.test','password'=>'FixtureOnlyPassword!2026']);
    $token = $user->createToken('isolated-runtime-probe')->plainTextToken;
    Route::middleware('api')->get('/api/_image_probe/failure', function () { throw new RuntimeException('fixture-password-do-not-expose'); });
    $kernel = $app->make(HttpKernel::class);
    function runtimeProbeRequest($app,$kernel,string $path,string $method='GET',array $headers=[],string $body='') {
        $app['auth']->forgetGuards();
        $server=['CONTENT_TYPE'=>'application/json'];
        foreach($headers as $key=>$value){$server['HTTP_'.strtoupper(str_replace('-','_',$key))]=$value;}
        $request=Request::create('https://api.zentunnel.net'.$path,$method,[],[],[],$server,$body);
        $response=$kernel->handle($request);$kernel->terminate($request,$response);return $response;
    }
    foreach(['','application/json','text/html'] as $accept){
        $headers=$accept ? ['Accept'=>$accept] : [];
        $r=runtimeProbeRequest($app,$kernel,'/api/v1/me','GET',$headers);
        runtimeProbeCheck($r->getStatusCode()===401 && json_decode($r->getContent(),true)['code']==='unauthenticated','401 JSON Accept variants');
        $r=runtimeProbeRequest($app,$kernel,'/api/v1/admin/dashboard','GET',[...$headers,'Authorization'=>'Bearer '.$token]);
        runtimeProbeCheck($r->getStatusCode()===403,'403 authorization');
        $r=runtimeProbeRequest($app,$kernel,'/api/v1/auth/login','POST',$headers,'{}');runtimeProbeCheck($r->getStatusCode()===422,'422 validation');
        $r=runtimeProbeRequest($app,$kernel,'/api/_image_probe/failure','GET',$headers);
        runtimeProbeCheck($r->getStatusCode()===500 && $r->getContent()==='{"message":"Server error.","code":"server_error"}','opaque 500 even APP_DEBUG=true');
    }
    foreach(['https://ott.zentunnel.net','https://cms.zentunnel.net'] as $origin){
        $r=runtimeProbeRequest($app,$kernel,'/api/v1/playback/sessions','OPTIONS',['Origin'=>$origin,'Access-Control-Request-Method'=>'POST','Access-Control-Request-Headers'=>'Authorization,Content-Type']);
        runtimeProbeCheck($r->getStatusCode()===204 && $r->headers->get('Access-Control-Allow-Origin')===$origin && $r->headers->get('Access-Control-Allow-Credentials')==='true','credentialed exact-origin preflight');
        $r=runtimeProbeRequest($app,$kernel,'/api/v1/me','GET',['Origin'=>$origin]);
        runtimeProbeCheck($r->getStatusCode()===401 && $r->headers->get('Access-Control-Allow-Origin')===$origin && $r->headers->get('Access-Control-Allow-Credentials')==='true','credentialed real error');
    }
    $r=runtimeProbeRequest($app,$kernel,'/api/v1/me','OPTIONS',['Origin'=>'https://evil.example','Access-Control-Request-Method'=>'GET']);
    runtimeProbeCheck(! $r->headers->has('Access-Control-Allow-Origin'),'hostile origin denied');
    $movie=App\Models\Movie::create(['title'=>'Isolated image fixture','slug'=>'isolated-image-fixture','published'=>true]);
    App\Models\VideoSource::create(['movie_id'=>$movie->id,'label'=>'main','kind'=>'hls','url'=>'https://cdn.goldendragon2000.com/vod/fixture/master.m3u8','enabled'=>true,'is_default'=>true]);
    $r=runtimeProbeRequest($app,$kernel,'/api/v1/playback/sessions','POST',['Authorization'=>'Bearer '.$token],json_encode(['movie_id'=>$movie->id]));
    runtimeProbeCheck($r->getStatusCode()===503 && ! str_contains($r->getContent(),'goldendragon') && ! isset(json_decode($r->getContent(),true)['data']['url']),'CDN playback fails closed for an authenticated valid movie');
    echo "PASS production runtime image: extensions, no local secrets/dev data, 401/403/422/opaque500, credentialed CORS, hostile-origin denial\n";
} catch(Throwable $e) {
    fwrite(STDERR, "Runtime image probe failed; details suppressed (no secret-bearing exception output).\n");exit(1);
}
