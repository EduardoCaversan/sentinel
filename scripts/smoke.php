<?php

declare(strict_types=1);

// Opt-in integration check against the running API, real queue, and public HTTPS.
// Creates a disposable account; no password or token is printed.
require __DIR__.'/../vendor/autoload.php';

use GuzzleHttp\Client;

$base = getenv('SMOKE_URL') ?: 'http://localhost';
$client = new Client(['base_uri' => $base, 'http_errors' => false, 'timeout' => 20]);
$token = null;
$monitorId = null;
$request = function (string $method, string $path, ?array $body = null, int $expected = 200) use ($client, &$token): array {
    $options = ['headers' => ['Accept' => 'application/json']];
    if ($token) {
        $options['headers']['Authorization'] = 'Bearer '.$token;
    }
    if ($body !== null) {
        $options['json'] = $body;
    }
    $response = $client->request($method, $path, $options);
    if ($response->getStatusCode() !== $expected) {
        throw new RuntimeException("$method $path returned {$response->getStatusCode()}, expected $expected.");
    }

    return json_decode((string) $response->getBody(), true) ?? [];
};
$assert = function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$poll = function (int $previousId) use (&$request, &$monitorId): array {
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $checks = $request('GET', "/api/v1/monitors/$monitorId/checks");
        if (isset($checks['data'][0]) && $checks['data'][0]['id'] > $previousId) {
            return $checks['data'][0];
        }
        usleep(500_000);
    }
    throw new RuntimeException('No new queued check completed in 15 seconds.');
};
try {
    $request('GET', '/health/ready');
    $password = bin2hex(random_bytes(16)).'A1';
    $email = 'smoke-'.bin2hex(random_bytes(6)).'@example.com';
    $registered = $request('POST', '/api/v1/auth/register', ['name' => 'Smoke Verification', 'email' => $email, 'password' => $password, 'password_confirmation' => $password], 201);
    $token = $registered['data']['token'];
    $request('GET', '/api/v1/auth/me');
    $request('POST', '/api/v1/auth/logout', expected: 204);
    $token = null;
    $token = $request('POST', '/api/v1/auth/login', ['email' => $email, 'password' => $password])['data']['token'];
    $created = $request('POST', '/api/v1/monitors', ['name' => 'Live HTTPS verification', 'url' => 'https://example.com', 'expected_status_code' => 503, 'interval_seconds' => 3600, 'failure_threshold' => 2, 'recovery_threshold' => 2], 201);
    $monitorId = $created['data']['id'];
    $last = 0;
    for ($i = 0; $i < 2; $i++) {
        $request('POST', "/api/v1/monitors/$monitorId/check", expected: 202);
        $check = $poll($last);
        $assert($check['http_status_code'] === 200, 'Public HTTPS probe did not return HTTP 200.');
        $last = $check['id'];
    }
    $assert($request('GET', "/api/v1/monitors/$monitorId")['data']['status'] === 'down', 'Failure threshold did not mark monitor down.');
    $incidents = $request('GET', "/api/v1/monitors/$monitorId/incidents");
    $assert($incidents['meta']['total'] === 1 && $incidents['data'][0]['status'] === 'open', 'Expected one open incident.');
    $request('PATCH', "/api/v1/monitors/$monitorId", ['expected_status_code' => 200]);
    for ($i = 0; $i < 2; $i++) {
        $request('POST', "/api/v1/monitors/$monitorId/check", expected: 202);
        $check = $poll($last);
        $assert($check['status'] === 'success', 'Recovery probe failed.');
        $last = $check['id'];
    }
    $assert($request('GET', "/api/v1/monitors/$monitorId")['data']['status'] === 'healthy', 'Recovery threshold did not mark monitor healthy.');
    $assert($request('GET', "/api/v1/monitors/$monitorId/incidents")['data'][0]['status'] === 'resolved', 'Incident was not resolved.');
    echo "PASS: readiness, registration, login, ownership context, real HTTPS, Redis queue, persisted checks, incident opening and recovery.\n";
} finally {
    if ($token && $monitorId) {
        $request('DELETE', "/api/v1/monitors/$monitorId", expected: 204);
    }
    if ($token) {
        $request('POST', '/api/v1/auth/logout', expected: 204);
    }
}
