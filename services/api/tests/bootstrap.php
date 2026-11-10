<?php

require __DIR__.'/../vendor/autoload.php';

/*
 * The suite must run against a disposable in-memory database and never reach the
 * real Postgres or Redis. phpunit.xml's <env> entries are not enough on their own:
 * an ambient CACHE_STORE/DB_CONNECTION/APP_ENV already present in the developer or
 * CI environment wins over them, so the values are forced here, before the
 * application is booted.
 */
$forced = [
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'true',
    'APP_MAINTENANCE_DRIVER' => 'file',
    'BCRYPT_ROUNDS' => '4',
    'BROADCAST_CONNECTION' => 'null',
    'CACHE_STORE' => 'array',
    'CACHE_DRIVER' => 'array',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_URL' => '',
    'QUEUE_CONNECTION' => 'sync',
    'QUEUE_DRIVER' => 'sync',
    'SESSION_DRIVER' => 'array',
    'MAIL_MAILER' => 'array',
    'TELESCOPE_ENABLED' => 'false',
    'NIGHTWATCH_ENABLED' => 'false',
    'PULSE_ENABLED' => 'false',
];

foreach ($forced as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

/*
 * Tests need an encryption key. Generate a throwaway one whenever the environment
 * carries none, so no key is committed and no deployed key is reused.
 */
$key = getenv('APP_KEY');

if ($key === false || trim((string) $key) === '') {
    $generated = 'base64:'.base64_encode(random_bytes(32));

    putenv('APP_KEY='.$generated);
    $_ENV['APP_KEY'] = $generated;
    $_SERVER['APP_KEY'] = $generated;
}
