<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (config('cache.default') !== 'redis') {
    throw new RuntimeException('This verification requires the Redis cache store.');
}
if (isset($argv[1])) {
    $contender = Cache::lock($argv[1], 30);
    $acquired = $contender->get();
    if ($acquired) {
        $contender->release();
    }
    exit($acquired === ($argv[2] === 'available') ? 0 : 1);
}
$key = 'verification:'.bin2hex(random_bytes(12));
$lock = Cache::lock($key, 30);
if (! $lock->get()) {
    throw new RuntimeException('Unable to acquire verification lock.');
}
try {
    (new Process([PHP_BINARY, __FILE__, $key, 'blocked']))->mustRun();
} finally {
    $lock->release();
}
(new Process([PHP_BINARY, __FILE__, $key, 'available']))->mustRun();
echo "PASS: a separate PHP process cannot acquire a held Redis lock and can acquire it after release.\n";
