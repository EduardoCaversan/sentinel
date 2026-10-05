<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Destructive ONLY to sentinel_testing, the dedicated disposable test database.
foreach (['APP_ENV' => 'testing', 'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'sentinel_testing', 'CACHE_STORE' => 'array', 'LOG_CHANNEL' => 'null'] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDatabaseName() !== 'sentinel_testing') {
    throw new RuntimeException('Isolated test database required.');
}
$paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($path) => ! str_contains($path, '2026_10_04')));
if (Artisan::call('migrate:fresh', ['--path' => $paths, '--realpath' => true, '--force' => true]) !== 0) {
    throw new RuntimeException('V1 schema creation failed.');
}
$user = DB::table('users')->insertGetId(['name' => 'Legacy owner', 'email' => 'upgrade-'.Str::uuid().'@example.test', 'password' => 'unusable-test-hash', 'created_at' => now(), 'updated_at' => now()]);
$monitor = DB::table('monitors')->insertGetId(['user_id' => $user, 'name' => 'Legacy monitor', 'url' => 'https://example.com', 'status' => 'down', 'consecutive_failures' => 3, 'created_at' => now(), 'updated_at' => now()]);
$check = DB::table('monitor_checks')->insertGetId(['monitor_id' => $monitor, 'execution_id' => (string) Str::uuid(), 'status' => 'failure', 'response_time_ms' => 25, 'checked_at' => now(), 'created_at' => now()]);
$incident = DB::table('incidents')->insertGetId(['monitor_id' => $monitor, 'failure_count' => 3, 'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
if (Artisan::call('migrate', ['--force' => true]) !== 0) {
    throw new RuntimeException('V2 upgrade failed.');
}
$upgraded = DB::table('monitors')->find($monitor);
$organization = DB::table('organizations')->find($upgraded->organization_id);
if ($organization->owner_id !== $user || $organization->personal_user_id !== $user ||
    ! DB::table('organization_user')->where(['organization_id' => $organization->id, 'user_id' => $user, 'role' => 'owner'])->exists() ||
    ! DB::table('monitor_checks')->where('id', $check)->exists() ||
    ! DB::table('incidents')->where('id', $incident)->where('status', 'open')->exists() ||
    $upgraded->consecutive_failures !== 3) {
    throw new RuntimeException('Legacy data or ownership was not preserved.');
}
DB::table('organizations')->where('id', $organization->id)->delete();
DB::table('users')->where('id', $user)->delete();
echo "PASS: fresh V1 schema, legacy owner/monitor/check/open incident, V2 upgrade; IDs, ownership and streak preserved.\n";
