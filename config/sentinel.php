<?php

return [
    'version' => '2.0.0',
    'retention_days' => max(1, min(365, (int) env('MAX_RETENTION_DAYS', 90), (int) env('RETENTION_DAYS', 30))),
    'max_retention_days' => max(1, min(365, (int) env('MAX_RETENTION_DAYS', 90))),
    'min_interval_seconds' => max(60, min(86400, (int) ceil((int) env('MIN_CHECK_INTERVAL_SECONDS', 60) / 60) * 60)),
    'response_max_bytes' => max(65536, (int) env('RESPONSE_MAX_BYTES', 1048576)),
    'quotas' => [
        'organizations' => max(1, (int) env('QUOTA_ORGANIZATIONS', 5)),
        'monitors' => max(1, (int) env('QUOTA_MONITORS', 50)),
        'members' => max(1, (int) env('QUOTA_MEMBERS', 25)),
        'api_keys' => max(1, (int) env('QUOTA_API_KEYS', 20)),
        'channels' => max(1, (int) env('QUOTA_NOTIFICATION_CHANNELS', 10)),
        'status_pages' => max(1, (int) env('QUOTA_STATUS_PAGES', 5)),
        'maintenance' => max(1, (int) env('QUOTA_MAINTENANCE_WINDOWS', 50)),
    ],
    'registration_enabled' => env('REGISTRATION_ENABLED', true),
    'demo_email' => env('DEMO_EMAIL'),
    'demo_password' => env('DEMO_PASSWORD'),
];
