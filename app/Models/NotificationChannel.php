<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property list<string> $events
 * @property bool $is_active
 */
class NotificationChannel extends Model
{
    public const EVENTS = ['incident.opened', 'incident.resolved', 'monitor.degraded', 'monitor.recovered'];

    protected $guarded = ['id'];

    protected $hidden = ['endpoint', 'signing_secret'];

    protected function casts(): array
    {
        return ['endpoint' => 'encrypted', 'signing_secret' => 'encrypted', 'events' => 'array', 'is_active' => 'boolean'];
    }
}
