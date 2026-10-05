<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IncidentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property IncidentStatus $status
 * @property Carbon $started_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $first_failed_at
 * @property Carbon|null $acknowledged_at
 */
class Incident extends Model
{
    protected $guarded = ['id', 'open_slot'];

    protected function casts(): array
    {
        return ['status' => IncidentStatus::class, 'started_at' => 'datetime', 'resolved_at' => 'datetime', 'acknowledged_at' => 'datetime', 'first_failed_at' => 'datetime'];
    }

    /** @return BelongsTo<Monitor, $this> */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    /** @return HasMany<IncidentEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(IncidentEvent::class);
    }

    public function durationSeconds(): int
    {
        return max(0, (int) $this->started_at->diffInSeconds($this->resolved_at ?? now()));
    }
}
