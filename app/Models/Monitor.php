<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MonitorStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * @property MonitorStatus $status
 * @property bool $is_active
 * @property float $slo_target
 * @property Carbon|null $last_checked_at
 * @property Carbon|null $next_check_at
 * @property-read Pivot $pivot
 */
class Monitor extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'url', 'expected_status_code', 'interval_seconds', 'timeout_seconds', 'failure_threshold', 'recovery_threshold', 'is_active', 'slo_target'];

    protected static function booted(): void
    {
        static::creating(function (Monitor $monitor): void {
            if (! $monitor->organization_id) {
                $monitor->organization_id = User::findOrFail($monitor->user_id)->personalOrganization()->id;
            }
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsToMany<MaintenanceWindow, $this> */
    public function maintenanceWindows(): BelongsToMany
    {
        return $this->belongsToMany(MaintenanceWindow::class, 'maintenance_monitor');
    }

    protected function casts(): array
    {
        return ['status' => MonitorStatus::class, 'is_active' => 'boolean', 'last_checked_at' => 'datetime', 'next_check_at' => 'datetime', 'slo_target' => 'float'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<MonitorCheck, $this> */
    public function checks(): HasMany
    {
        return $this->hasMany(MonitorCheck::class);
    }

    /** @return HasMany<Incident, $this> */
    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }
}
