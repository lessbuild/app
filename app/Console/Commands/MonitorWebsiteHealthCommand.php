<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\Website;
use App\Notifications\FailureNotification;
use App\Services\ActivityRecorder;
use App\Services\Runner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class MonitorWebsiteHealthCommand extends Command
{
    protected $signature = 'lessbuild:websites:health {--website=* : Check only these website IDs}';

    protected $description = 'Check enabled websites from their managed servers and record health transitions';

    public function handle(Runner $runner, ActivityRecorder $activity): int
    {
        $ids = collect($this->option('website'))
            ->filter(fn ($id) => filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
        $batchSize = max(1, (int) config('lessbuild.health_monitor_batch_size'));
        $query = Website::query()
            ->where('health_check_enabled', true)
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
            [$successful, $error] = $this->check($website, $runner);
            $becameUnhealthy = $this->recordResult($website, $successful, $error, $activity);
            $checked++;
            $unhealthy += (int) $becameUnhealthy;
        }

        $this->info("Checked {$checked} website(s); {$unhealthy} new outage(s).");

        return self::SUCCESS;
    }

    /** @return array{bool, ?string} */
    private function check(Website $website, Runner $runner): array
    {
        $url = escapeshellarg("http://{$website->url}{$website->health_check_path}");
        $command = <<<BASH
        curl --fail --silent --show-error --location \
            --connect-timeout 5 --max-time 15 \
            --retry 1 --retry-delay 1 --retry-all-errors \
            --user-agent "lessbuild-health-monitor" \
            --output /dev/null {$url}
        BASH;

        try {
            $process = $runner->server($website->server)->create()->execute($command);
        } catch (Throwable $exception) {
            report($exception);

            return [false, str($exception->getMessage())->limit(500)->toString() ?: 'Unable to reach the managed server.'];
        }

        if ($process->isSuccessful()) {
            return [true, null];
        }

        $error = trim($process->getErrorOutput() ?: $process->getOutput());

        return [false, str($error ?: 'The website did not return a successful response.')->limit(500)->toString()];
    }

    private function recordResult(
        Website $website,
        bool $successful,
        ?string $error,
        ActivityRecorder $activity,
    ): bool {
        return DB::transaction(function () use ($website, $successful, $error, $activity): bool {
            $locked = Website::query()->lockForUpdate()->find($website->id);
            if (! $locked
                || ! $locked->health_check_enabled
                || $locked->provisioning_status !== Website::STATUS_ACTIVE
                || $locked->server?->provisioning_status !== Server::STATUS_ACTIVE
                || $locked->server_id !== $website->server_id
                || $locked->url !== $website->url
                || $locked->health_check_path !== $website->health_check_path
                || $locked->getRawOriginal('health_last_checked_at') !== $website->getRawOriginal('health_last_checked_at')) {
                return false;
            }

            $previousStatus = $locked->health_status;
            if ($successful) {
                $locked->update([
                    'health_status' => Website::HEALTH_HEALTHY,
                    'health_failure_count' => 0,
                    'health_last_checked_at' => now(),
                    'health_last_error' => null,
                ]);
                if ($previousStatus === Website::HEALTH_UNHEALTHY) {
                    $activity->record($locked, $locked->user_id, 'website', "Website \"{$locked->name}\" recovered.");
                }

                return false;
            }

            $failureCount = min(65535, $locked->health_failure_count + 1);
            $threshold = max(1, (int) config('lessbuild.health_monitor_failure_threshold'));
            $nextStatus = $failureCount >= $threshold ? Website::HEALTH_UNHEALTHY : $previousStatus;
            $locked->update([
                'health_status' => $nextStatus,
                'health_failure_count' => $failureCount,
                'health_last_checked_at' => now(),
                'health_last_error' => $error,
            ]);
            if ($nextStatus !== Website::HEALTH_UNHEALTHY || $previousStatus === Website::HEALTH_UNHEALTHY) {
                return false;
            }

            $activity->record($locked, $locked->user_id, 'website', "Website \"{$locked->name}\" is unhealthy.");
            $locked->user?->notify(new FailureNotification(
                'website',
                $locked->id,
                "Website \"{$locked->name}\" is unhealthy",
                $error ?: 'The website did not return a successful response.',
            ));

            return true;
        });
    }
}
