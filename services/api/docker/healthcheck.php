<?php

use App\Support\ServiceHeartbeat;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

// No secret-bearing exception messages or connection strings reach health logs.
require __DIR__.'/../vendor/autoload.php';
try {
    $role = $argv[1] ?? '';
    $data = ServiceHeartbeat::fresh($role, $role === 'worker' ? 120 : 150);
    if ($data === null) {
        throw new RuntimeException('heartbeat');
    }
    $command = @file_get_contents('/proc/'.$data['pid'].'/cmdline');
    $expected = $role === 'worker' ? 'queue:work' : 'run-scheduler.sh';
    if (! is_string($command) || ! str_contains($command, $expected)) {
        throw new RuntimeException('process');
    }
    $app = require __DIR__.'/../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    DB::select('SELECT 1');
    Redis::connection()->ping();
    echo $role." healthy\n";
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "CLI service unhealthy (heartbeat/process/dependency); inspect sanitized application logs.\n");
    exit(1);
}
