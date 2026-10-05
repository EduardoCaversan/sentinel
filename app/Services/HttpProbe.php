<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CheckStatus;
use App\Exceptions\ResponseTooLarge;
use App\Exceptions\UnsafeTarget;
use App\Models\Monitor;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class HttpProbe
{
    public function __construct(private PublicTarget $targets) {}

    public function run(Monitor $monitor): array
    {
        $start = hrtime(true);
        $result = ['status' => CheckStatus::Failure, 'http_status_code' => null, 'error_type' => null, 'error_message' => null, 'checked_at' => now()];
        $sink = fopen(PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'wb');
        try {
            $target = $this->targets->resolve($monitor->url);
            $ip = str_contains($target['ip'], ':') ? '['.$target['ip'].']' : $target['ip'];
            $curl = [CURLOPT_PROXY => '', CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS];
            if (! filter_var($target['host'], FILTER_VALIDATE_IP)) {
                $curl[CURLOPT_RESOLVE] = ["{$target['host']}:{$target['port']}:{$ip}"];
            }
            $response = Http::withOptions([
                'curl' => $curl,
                'proxy' => '',
                'allow_redirects' => false,
                'sink' => $sink,
                'verify' => true,
                'progress' => function ($total, $downloaded): void {
                    if ($downloaded > config('sentinel.response_max_bytes')) {
                        throw new ResponseTooLarge;
                    }
                },
            ])->setHandler(new CurlHandler) // Stream handlers cannot enforce CURLOPT_RESOLVE.
                ->connectTimeout(min(5, $monitor->timeout_seconds))
                ->timeout($monitor->timeout_seconds)
                ->withUserAgent('Sentinel/2.0')
                ->get($monitor->url);
            $result['http_status_code'] = $response->status();
            $result['status'] = $response->status() === $monitor->expected_status_code ? CheckStatus::Success : CheckStatus::Failure;
            if ($result['status'] === CheckStatus::Failure) {
                $result['error_type'] = 'unexpected_status';
                $result['error_message'] = 'The endpoint returned an unexpected HTTP status.';
            }
        } catch (ResponseTooLarge) {
            $result['error_type'] = 'response_too_large';
            $result['error_message'] = 'The endpoint exceeded the response size limit.';
        } catch (UnsafeTarget $exception) {
            $result['error_type'] = 'unsafe_target';
            $result['error_message'] = 'Target blocked or DNS resolution failed.';
        } catch (ConnectionException $exception) {
            $previous = $exception->getPrevious();
            $context = $previous && method_exists($previous, 'getHandlerContext') ? $previous->getHandlerContext() : [];
            $timeout = ($context['errno'] ?? null) === 28;
            $result['status'] = $timeout ? CheckStatus::Timeout : CheckStatus::Failure;
            $result['error_type'] = $timeout ? 'timeout' : 'connection';
            $result['error_message'] = $timeout ? 'The endpoint exceeded its timeout.' : 'Unable to establish a secure connection to the endpoint.';
        } catch (TransferException) {
            $result['error_type'] = 'connection';
            $result['error_message'] = 'The endpoint transfer failed.';
        } finally {
            if (is_resource($sink)) {
                fclose($sink);
            }
        }
        $result['response_time_ms'] = max(0, (int) round((hrtime(true) - $start) / 1_000_000));

        return $result;
    }
}
