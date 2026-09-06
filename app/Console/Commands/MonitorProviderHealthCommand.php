<?php

namespace App\Console\Commands;

use App\Models\Provider;
use App\Services\Entitlements;
use App\Services\ProviderHealthMonitor;
use Illuminate\Console\Command;

class MonitorProviderHealthCommand extends Command
{
    protected $signature = 'lessbuild:providers:health {--provider=* : Check only these provider IDs}';

    protected $description = 'Check stale provider credentials and record connection health transitions';

    public function handle(ProviderHealthMonitor $monitor, Entitlements $entitlements): int
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
            $query->where(function ($query): void {
                $query->whereNull('connection_checked_at');
                foreach (Provider::CONNECTION_CHECK_INTERVALS as $minutes) {
                    $query->orWhere(function ($query) use ($minutes): void {
                        $query
                            ->where('connection_check_interval_minutes', $minutes)
                            // The timer runs every five minutes. This allowance
                            // prevents execution time from missing its next window.
                            ->where('connection_checked_at', '<=', now()->subMinutes($minutes - 1));
                    });
                }
            });
        }

        $checked = 0;
        $failed = 0;
        $discarded = 0;
        foreach ($query->limit($batchSize)->get() as $provider) {
            if (! $provider->organization || ! $entitlements->allows($provider->organization, 'monitoring')) {
                $discarded++;

                continue;
            }
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
