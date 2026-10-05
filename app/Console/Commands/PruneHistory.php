<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MonitorCheck;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneHistory extends Command
{
    protected $signature = 'sentinel:prune';

    protected $description = 'Delete expired check/delivery history in bounded batches';

    public function handle(): int
    {
        $deleted = 0;
        Organization::query()->chunkById(100, function ($organizations) use (&$deleted): void {
            foreach ($organizations as $organization) {
                $cutoff = now()->subDays(max(1, min($organization->retention_days, config('sentinel.max_retention_days'))));
                do {
                    $ids = MonitorCheck::whereHas('monitor', fn ($q) => $q->where('organization_id', $organization->id))->where('checked_at', '<', $cutoff)->limit(1000)->pluck('id');
                    $deleted += MonitorCheck::whereIn('id', $ids)->delete();
                } while ($ids->count() === 1000);
                do {
                    $ids = NotificationDelivery::where('organization_id', $organization->id)->where('created_at', '<', $cutoff)->limit(1000)->pluck('id');
                    NotificationDelivery::whereIn('id', $ids)->delete();
                } while ($ids->count() === 1000);
            }
        });
        do {
            $ids = DB::table('check_executions')->where('created_at', '<', now()->subDays(7))->limit(1000)->pluck('execution_id');
            DB::table('check_executions')->whereIn('execution_id', $ids)->delete();
        } while ($ids->count() === 1000);
        DB::table('organization_invitations')->where('expires_at', '<', now()->subDay())->delete();
        $this->info("Pruned $deleted checks. Incident summaries and human timeline events are retained.");

        return self::SUCCESS;
    }
}
