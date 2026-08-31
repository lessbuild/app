<?php

namespace App\Console\Commands;

use App\Models\Provider;
use App\Services\ProviderHealthMonitor;
use Illuminate\Console\Command;

class MonitorProviderHealthCommand extends Command
{
    protected $signature = 'lessbuild:providers:health {--provider=* : Check only these provider IDs}';

    protected $description = 'Check stale provider credentials and record connection health transitions';

    public function handle(ProviderHealthMonitor $monitor): int
    {
        $ids = collect($this->option('provider'))
            ->filter(fn ($id) => filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
        $batchSize = max(1, (int) config('lessbuild.provider_health_batch_size'));
        $query = Provider::query()
            ->where('connection_monitoring_enabled', true)
            ->orderByRaw('connection_checked_at IS NOT NULL')
            ->orderBy('connection_checked_at')
            ->orderBy('id');

        if ($ids->isNotEmpty()) {
            $query->whereKey($ids);
        } else {
            $interval = max(5, (int) config('lessbuild.provider_health_interval_minutes'));
            $query->where(function ($query) use ($interval): void {
                $query
                    ->whereNull('connection_checked_at')
                    ->orWhere('connection_checked_at', '<=', now()->subMinutes($interval));
            });
        }

        $checked = 0;
        $failed = 0;
        $discarded = 0;
        foreach ($query->limit($batchSize)->get() as $provider) {
            $result = $monitor->check($provider, automatic: true);
            if (! $result['recorded']) {
                $discarded++;

                continue;
            }

            $checked++;
            $failed += (int) ! $result['successful'];
        }

        $this->info("Checked {$checked} provider(s); {$failed} failed; {$discarded} stale result(s) discarded.");

        return self::SUCCESS;
    }
}
