<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\UnsafeTarget;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Support\Facades\Http;
use Throwable;

class WebhookSender
{
    public function __construct(private PublicTarget $targets) {}

    public function send(NotificationChannel $channel, NotificationDelivery $delivery): array
    {
        $start = hrtime(true);
        $result = ['http_status' => null, 'error_type' => null, 'retryable' => false];
        $sink = fopen(PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'wb');
        try {
            $target = $this->targets->resolve($channel->endpoint);
            $ip = str_contains($target['ip'], ':') ? '['.$target['ip'].']' : $target['ip'];
            $curl = [CURLOPT_PROXY => '', CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS];
            if (! filter_var($target['host'], FILTER_VALIDATE_IP)) {
                $curl[CURLOPT_RESOLVE] = ["{$target['host']}:{$target['port']}:{$ip}"];
            }
            $text = $delivery->payload['monitor']['name'].' — '.$delivery->event;
            $payload = match ($channel->type) {
                'slack' => ['blocks' => [['type' => 'section', 'text' => ['type' => 'plain_text', 'text' => $text]]]],
                'discord' => ['content' => $text, 'allowed_mentions' => ['parse' => []]],
                default => ['id' => $delivery->public_id, ...$delivery->payload],
            };
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $timestamp = (string) time();
            $headers = ['Idempotency-Key' => $delivery->public_id, 'X-Sentinel-Event' => $delivery->event, 'X-Sentinel-Timestamp' => $timestamp];
            if ($channel->signing_secret) {
                $headers['X-Sentinel-Signature'] = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $channel->signing_secret);
            }
            $response = Http::withOptions([
                'curl' => $curl, 'proxy' => '', 'allow_redirects' => false, 'verify' => true, 'sink' => $sink,
                'progress' => function ($total, $downloaded): void {
                    if ($downloaded > config('sentinel.response_max_bytes')) {
                        throw new \RuntimeException('Response size exceeded.');
                    }
                },
            ])->setHandler(new CurlHandler)->timeout(10)->connectTimeout(5)->withHeaders($headers)->withBody($body, 'application/json')->post($channel->endpoint);
            $result['http_status'] = $response->status();
            if (! $response->successful()) {
                $result['error_type'] = 'http_status';
                $result['retryable'] = $response->status() >= 500 || in_array($response->status(), [408, 425, 429], true);
            }
        } catch (UnsafeTarget) {
            $result['error_type'] = 'unsafe_target';
        } catch (Throwable) {
            // Never propagate transport exceptions: webhook paths contain secrets.
            $result['error_type'] = 'transport';
            $result['retryable'] = true;
        } finally {
            if (is_resource($sink)) {
                fclose($sink);
            }
        }
        $result['duration_ms'] = max(0, (int) round((hrtime(true) - $start) / 1_000_000));

        return $result;
    }
}
