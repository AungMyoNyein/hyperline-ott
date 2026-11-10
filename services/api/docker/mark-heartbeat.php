<?php

use App\Support\ServiceHeartbeat;

require __DIR__.'/../vendor/autoload.php';
ServiceHeartbeat::mark($argv[1] ?? '', isset($argv[2]) ? (int) $argv[2] : null);
