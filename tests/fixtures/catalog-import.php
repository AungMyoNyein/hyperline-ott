<?php
// Optional real-input browser fixture. This MUST NEVER connect to UAT PostgreSQL.
require __DIR__.'/../../services/api/vendor/autoload.php';
$app=require __DIR__.'/../../services/api/bootstrap/app.php';
$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
if (! app()->environment('testing') || config('database.default')!=='sqlite' || config('database.connections.sqlite.database')!=='/tmp/hyperline-browser.sqlite') {
    throw new RuntimeException('Catalog browser fixture requires the disposable SQLite harness');
}
$exit=$kernel->call('hyperline:import-youtube-catalog',[
    '--source-file'=>getenv('HYPERLINE_CATALOG_CSV'),
    '--verification-report'=>getenv('HYPERLINE_CATALOG_VERIFICATION'),
    '--batch'=>'isolated-browser-catalog',
]);
$report=json_decode($kernel->output(),true,512,JSON_THROW_ON_ERROR);
if ($exit!==0 || ($report['summary']['dispositions']['created_draft']??0)!==25) {
    throw new RuntimeException('Expected 25 imported browser-only drafts');
}
echo "Catalog browser fixture: 25 imported drafts; all public content unchanged.\n";
