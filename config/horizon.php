<?php

return [
    'name' => 'Sentinel',
    'domain' => null,
    'path' => 'horizon',
    'use' => 'default',
    'prefix' => env('HORIZON_PREFIX', 'sentinel_horizon:'),
    'middleware' => ['web'],
    'waits' => ['redis:checks' => 60],
    'trim' => ['recent' => 60, 'pending' => 60, 'completed' => 60, 'recent_failed' => 10080, 'failed' => 10080, 'monitored' => 10080],
    'silenced' => [],
    'metrics' => ['trim_snapshots' => ['job' => 24, 'queue' => 24]],
    'fast_termination' => false,
    'memory_limit' => 64,
    'defaults' => [
        'notifications' => [
            'connection' => 'redis', 'queue' => ['notifications'], 'balance' => 'simple',
            'minProcesses' => 1, 'maxProcesses' => 1, 'maxTime' => 3600, 'maxJobs' => 500,
            'memory' => 128, 'tries' => 1, 'timeout' => 45, 'nice' => 0,
        ],
        'checks' => [
            'connection' => 'redis', 'queue' => ['checks'], 'balance' => 'auto',
            'autoScalingStrategy' => 'time', 'minProcesses' => 1, 'maxProcesses' => 2,
            'maxTime' => 3600, 'maxJobs' => 1000, 'memory' => 128,
            'tries' => 1, 'timeout' => 60, 'nice' => 0,
        ],
    ],
    'environments' => ['production' => ['checks' => [], 'notifications' => []], 'local' => ['checks' => [], 'notifications' => []]],
];
