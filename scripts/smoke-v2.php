<?php

declare(strict_types=1);

use GuzzleHttp\Client;

require __DIR__.'/../vendor/autoload.php';

// Opt-in smoke against a running development instance; no credentials are printed.
$client = new Client(['base_uri' => getenv('SMOKE_URL') ?: 'http://localhost', 'http_errors' => false, 'timeout' => 20]);
$credential = null;
$personalToken = null;
$resources = [];
$call = function (string $method, string $path, ?array $body = null, int $expected = 200) use ($client, &$credential): array {
    $options = ['headers' => ['Accept' => 'application/json']];
    if ($credential) {
        $options['headers']['Authorization'] = 'Bearer '.$credential;
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
try {
    $password = bin2hex(random_bytes(16)).'A1';
    $auth = $call('POST', '/api/v2/auth/register', ['name' => 'V2 smoke', 'email' => 'v2-smoke-'.bin2hex(random_bytes(6)).'@example.com', 'password' => $password, 'password_confirmation' => $password], 201);
    $personalToken = $credential = $auth['data']['token'];
    $organization = $call('POST', '/api/v2/organizations', ['name' => 'V2 smoke verification'], 201)['data']['id'];
    $prefix = "/api/v2/organizations/$organization";
    $key = $call('POST', "$prefix/api-keys", ['name' => 'Smoke automation', 'scopes' => ['monitors:read', 'monitors:write', 'incidents:read', 'incidents:write', 'analytics:read']], 201)['data'];
    $resources[] = "$prefix/api-keys/{$key['id']}";
    $credential = $key['secret'];
    $call('GET', "$prefix/api-keys", expected: 403);
    $monitor = $call('POST', "$prefix/monitors", ['name' => 'Smoke public HTTPS', 'url' => 'https://example.com', 'expected_status_code' => 503, 'failure_threshold' => 1, 'recovery_threshold' => 1, 'interval_seconds' => 3600], 201)['data']['id'];
    $resources[] = "$prefix/monitors/$monitor";
    $poll = function (int $previous) use ($call, $prefix, $monitor): array {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $checks = $call('GET', "$prefix/monitors/$monitor/checks")['data'];
            if (isset($checks[0]) && $checks[0]['id'] > $previous) {
                return $checks[0];
            }
            usleep(500000);
        }
        throw new RuntimeException('Queued V2 check did not complete.');
    };
    $call('POST', "$prefix/monitors/$monitor/check", expected: 202);
    $check = $poll(0);
    $assert($check['http_status_code'] === 200 && $check['status'] === 'failure', 'Expected a real successful HTTP transfer classified as unexpected status.');
    $incident = $call('GET', "$prefix/monitors/$monitor/incidents")['data'][0]['id'];
    $ack = $call('POST', "$prefix/incidents/$incident/acknowledge")['data'];
    $assert($ack['acknowledged_by_key'] === $key['id'], 'API key acknowledgement attribution failed.');
    $call('POST', "$prefix/incidents/$incident/notes", ['message' => 'Verified by V2 smoke.'], 201);
    $timeline = $call('GET', "$prefix/incidents/$incident/timeline")['data'];
    $assert(in_array('acknowledged', array_column($timeline, 'type'), true), 'Acknowledgement absent from timeline.');
    // Database timestamps have second precision; move past the half-open end boundary.
    usleep(1100000);
    $metrics = $call('GET', "$prefix/monitors/$monitor/analytics")['data'];
    $assert($metrics['failed_checks'] >= 1 && $metrics['slo']['error_budget_remaining_checks'] == 0, 'Analytics/SLO sanity check failed.');
    $credential = $personalToken;
    $maintenance = $call('POST', "$prefix/maintenance", ['description' => 'Smoke future maintenance', 'start_at' => gmdate('c', time() + 3600), 'end_at' => gmdate('c', time() + 7200), 'monitor_ids' => [$monitor]], 201)['data']['id'];
    $resources[] = "$prefix/maintenance/$maintenance";
    $slug = 'smoke-'.bin2hex(random_bytes(6));
    $page = $call('POST', "$prefix/status-pages", ['name' => 'Smoke Status', 'slug' => $slug, 'is_published' => true, 'components' => [['monitor_id' => $monitor, 'name' => 'Public component']]], 201)['data']['id'];
    $resources[] = "$prefix/status-pages/$page";
    $public = $call('GET', "/api/v2/status/$slug")['data'];
    $assert($public['status'] === 'down' && ! str_contains(json_encode($public), 'example.com'), 'Public status or privacy check failed.');
    $html = $client->get("/status/$slug");
    $assert($html->getStatusCode() === 200 && str_contains((string) $html->getBody(), 'Public component'), 'Public HTML did not render.');
    // Disabled channel verifies encrypted persistence without contacting any external receiver.
    $channel = $call('POST', "$prefix/notification-channels", ['name' => 'Disabled smoke receiver', 'type' => 'webhook', 'endpoint' => 'https://example.com/webhook', 'events' => ['incident.opened'], 'is_active' => false], 201)['data'];
    $resources[] = "$prefix/notification-channels/{$channel['id']}";
    $assert(! isset($channel['endpoint']), 'Webhook endpoint leaked.');
    $call('PATCH', "$prefix/monitors/$monitor", ['expected_status_code' => 200]);
    $call('POST', "$prefix/monitors/$monitor/check", expected: 202);
    $assert($poll($check['id'])['status'] === 'success', 'Recovery transfer failed.');
    $assert($call('GET', "$prefix/incidents/$incident")['data']['status'] === 'resolved', 'Automatic resolution failed.');
    echo "PASS: V2 organization, scoped key, real queued HTTP check, key acknowledgement, notes/timeline, analytics/SLO, maintenance, public JSON/HTML privacy, encrypted channel and automatic recovery.\n";
} finally {
    if ($personalToken) {
        $credential = $personalToken;
        foreach (array_reverse($resources) as $path) {
            $call('DELETE', $path, expected: 204);
        }
        $call('POST', '/api/v2/auth/logout', expected: 204);
    }
}
