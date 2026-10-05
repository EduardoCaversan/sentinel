<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property array{monitor: array{id: int, name: string, status: string}, event: string, occurred_at: string, incident_id: int|null} $payload
 * @property Carbon $next_attempt_at
 * @property Carbon|null $delivered_at
 */
class NotificationDelivery extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'next_attempt_at' => 'datetime', 'delivered_at' => 'datetime'];
    }

    /** @return BelongsTo<NotificationChannel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(NotificationChannel::class, 'notification_channel_id');
    }

    /** @return HasMany<NotificationAttempt, $this> */
    public function deliveryAttempts(): HasMany
    {
        return $this->hasMany(NotificationAttempt::class);
    }
}
