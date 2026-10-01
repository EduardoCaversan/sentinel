<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MonitorStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Monitor extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'url', 'expected_status_code', 'interval_seconds', 'timeout_seconds', 'failure_threshold', 'recovery_threshold', 'is_active'];

    protected function casts(): array
    {
        return ['status' => MonitorStatus::class, 'is_active' => 'boolean', 'last_checked_at' => 'datetime', 'next_check_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function checks(): HasMany
    {
        return $this->hasMany(MonitorCheck::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }
}
