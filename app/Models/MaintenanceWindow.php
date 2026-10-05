<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $start_at
 * @property Carbon $end_at
 */
class MaintenanceWindow extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['start_at' => 'datetime', 'end_at' => 'datetime'];
    }

    /** @return BelongsToMany<Monitor, $this> */
    public function monitors(): BelongsToMany
    {
        return $this->belongsToMany(Monitor::class, 'maintenance_monitor');
    }

    public function state(): string
    {
        return now()->lt($this->start_at) ? 'scheduled' : (now()->lt($this->end_at) ? 'active' : 'completed');
    }
}
