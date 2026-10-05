<?php

declare(strict_types=1);

use App\Enums\CheckStatus;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\User;
use App\Services\Quota;
use App\Services\RecordCheck;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;

// Dedicated test database only. Never touches the running application's data.
foreach (['APP_ENV' => 'testing', 'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'sentinel_testing', 'CACHE_STORE' => 'array', 'LOG_CHANNEL' => 'null', 'BCRYPT_ROUNDS' => '4'] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDatabaseName() !== 'sentinel_testing') {
    throw new RuntimeException('Isolated test database required.');
}
if (($argv[1] ?? null) === 'check') {
    $monitor = Monitor::findOrFail((int) $argv[2]);
    app(RecordCheck::class)->store($monitor, $argv[3], ['status' => CheckStatus::Failure, 'http_status_code' => 503, 'response_time_ms' => 20, 'checked_at' => now()]);
    exit(0);
}
if (($argv[1] ?? null) === 'quota') {
    config(['sentinel.quotas.monitors' => 2]);
    $organization = Organization::findOrFail((int) $argv[2]);
    try {
        app(Quota::class)->create($organization, 'monitors', fn () => $organization->monitors()->create(['name' => 'Concurrent monitor', 'url' => 'https://example.com']));
        exit(0);
    } catch (HttpException $exception) {
        exit($exception->getStatusCode() === 409 ? 22 : 1);
    }
}
$user = User::create(['name' => 'Concurrency verification', 'email' => Str::uuid().'@example.test', 'password' => Str::random(40)]);
$organization = new Organization(['name' => 'Concurrency verification']);
$organization->owner_id = $user->id;
$organization->save();
try {
    $monitor = $organization->monitors()->create(['name' => 'Concurrent check', 'url' => 'https://example.com', 'failure_threshold' => 1])->refresh();
    NotificationChannel::create(['organization_id' => $organization->id, 'name' => 'Outbox only', 'type' => 'webhook', 'endpoint' => 'https://example.com/webhook', 'events' => ['incident.opened'], 'is_active' => true]);
    $execution = (string) Str::uuid();
    DB::beginTransaction();
    Monitor::whereKey($monitor->id)->lockForUpdate()->firstOrFail();
    $workers = [];
    foreach (range(1, 3) as $index) {
        $worker = new Process([PHP_BINARY, __FILE__, 'check', (string) $monitor->id, $execution]);
        $worker->setTimeout(20)->start();
        $workers[] = $worker;
    }
    usleep(300000);
    DB::commit();
    foreach ($workers as $worker) {
        $worker->wait();
        if (! $worker->isSuccessful()) {
            throw new RuntimeException('Concurrent check child failed: '.$worker->getErrorOutput());
        }
    }
    if ($monitor->checks()->count() !== 1 || $monitor->incidents()->count() !== 1 || NotificationDelivery::where('monitor_id', $monitor->id)->count() !== 1) {
        throw new RuntimeException('Concurrent execution was not idempotent.');
    }
    DB::beginTransaction();
    Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
    $workers = [];
    foreach (range(1, 2) as $index) {
        $worker = new Process([PHP_BINARY, __FILE__, 'quota', (string) $organization->id]);
        $worker->setTimeout(20)->start();
        $workers[] = $worker;
    }
    usleep(300000);
    DB::commit();
    $codes = array_map(fn (Process $worker) => $worker->wait(), $workers);
    sort($codes);
    if ($codes !== [0, 22] || $organization->monitors()->count() !== 2) {
        throw new RuntimeException('Concurrent organization quota was exceeded.');
    }
    echo "PASS: three processes, one execution/check/incident/outbox delivery; two concurrent creates, one quota slot.\n";
} finally {
    if (DB::transactionLevel()) {
        DB::rollBack();
    }
    $organization->delete();
    $user->delete();
}
