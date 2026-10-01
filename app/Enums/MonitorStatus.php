<?php

declare(strict_types=1);

namespace App\Enums;

enum MonitorStatus: string
{
    case Unknown = 'unknown';
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Down = 'down';
}
