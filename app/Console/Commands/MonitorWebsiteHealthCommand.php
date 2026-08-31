<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\Website;
use App\Services\WebsiteHealthMonitor;
use Illuminate\Console\Command;

class MonitorWebsiteHealthCommand extends Command
{
    protected $signature = 'lessbuild:websites:health {--website=* : Check only these website IDs}';

    protected $description = 'Check enabled websites from their managed servers and record health transitions';

    public function handle(WebsiteHealthMonitor $monitor): int
    {
        $ids = collect($this->option('website'))
            ->filter(fn ($id) => filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
        $batchSize = max(1, (int) config('lessbuild.health_monitor_batch_size'));
        $query = Website::query()
            ->where('health_check_enabled', true)
            ->where('health_monitoring_enabled', true)
            ->where('provisioning_status', Website::STATUS_ACTIVE)
            ->whereHas('server', fn ($query) => $query->where('provisioning_status', Server::STATUS_ACTIVE))
            ->with('server')
            ->orderByRaw('health_last_checked_at IS NOT NULL')
            ->orderBy('health_last_checked_at')
            ->orderBy('id');

        if ($ids->isNotEmpty()) {
            $query->whereKey($ids);
        } else {
            $query->where(function ($query): void {
                $query
                    ->whereNull('health_last_checked_at')
                    ->orWhere('health_last_checked_at', '<=', now()->subMinutes(4));
            });
        }

        $checked = 0;
        $unhealthy = 0;
        foreach ($query->limit($batchSize)->get() as $website) {
            $becameUnhealthy = $monitor->check($website, automatic: true);
            if ($becameUnhealthy === null) {
                continue;
            }

            $checked++;
            $unhealthy += (int) $becameUnhealthy;
        }

        $this->info("Checked {$checked} website(s); {$unhealthy} new outage(s).");

        return self::SUCCESS;
    }
}
