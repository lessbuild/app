<?php

namespace App\Console\Commands;

use App\Actions\Repository\CancelDeploymentAction;
use App\Models\Build;
use App\Services\Runner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class WatchStaleDeploymentsCommand extends Command
{
    protected $signature = 'lessbuild:deployments:watchdog
        {--minutes= : Minutes without a heartbeat before a deployment is stale}';

    protected $description = 'Stop stale remote deployments and release their repository locks';

    public function handle(Runner $runner): int
    {
        $minutes = max(1, (int) ($this->option('minutes') ?: config('lessbuild.deployment_stale_minutes')));
        $cutoff = now()->subMinutes($minutes);
        $processed = 0;

        Build::query()
            ->whereIn('status', Build::ACTIVE_STATUSES)
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $buildId) use ($runner, $cutoff, $minutes, &$processed): void {
                $build = $this->claim($buildId, $cutoff, $minutes);
                if (! $build) {
                    return;
                }

                if ($build->remote_process_path === null && $build->started_at === null) {
                    $this->finish($build, null, $minutes);
                    $processed++;

                    return;
                }

                try {
                    $log = (new CancelDeploymentAction($build, $runner))->handle();
                } catch (Throwable $exception) {
                    report($exception);
                    Build::query()
                        ->whereKey($build->id)
                        ->where('status', Build::STATUS_TIMING_OUT)
                        ->update([
                            'failure_message' => 'Deployment timed out. Remote process cancellation will be retried automatically.',
                        ]);
                    $this->warn("Build {$build->id}: remote cancellation will be retried.");

                    return;
                }

                $this->finish($build, $log, $minutes);
                $processed++;
            });

        $this->info("Recovered {$processed} stale deployment(s).");

        return self::SUCCESS;
    }

    private function claim(int $buildId, Carbon $cutoff, int $minutes): ?Build
    {
        return DB::transaction(function () use ($buildId, $cutoff, $minutes): ?Build {
            $build = Build::query()->lockForUpdate()->find($buildId);
            if ($build?->statusEnum()?->isActive() !== true) {
                return null;
            }

            if ($build->status === Build::STATUS_TIMING_OUT) {
                if ($build->last_heartbeat_at?->isAfter(now()->subMinutes(2))) {
                    return null;
                }

                $build->update(['last_heartbeat_at' => now()]);
            } else {
                $lastSeen = $build->last_heartbeat_at ?? $build->started_at ?? $build->created_at;
                if ($lastSeen === null || $lastSeen->isAfter($cutoff)) {
                    return null;
                }

                $build->update([
                    'status' => Build::STATUS_TIMING_OUT,
                    'last_heartbeat_at' => now(),
                    'failure_message' => "Deployment stopped reporting progress for {$minutes} minutes.",
                ]);
            }

            return $build->fresh(['repository.website.server']);
        });
    }

    private function finish(Build $build, ?string $log, int $minutes): void
    {
        DB::transaction(function () use ($build, $log, $minutes): void {
            $locked = Build::query()
                ->whereKey($build->id)
                ->where('status', Build::STATUS_TIMING_OUT)
                ->lockForUpdate()
                ->first();
            if (! $locked) {
                return;
            }

            if ($log !== null) {
                $locked->logs()->updateOrCreate(
                    ['type' => Build::DEPLOYMENT_LOG_TYPE],
                    ['log' => $log],
                );
            }

            $locked->update([
                'status' => Build::STATUS_FAILED,
                'remote_process_id' => null,
                'remote_process_path' => null,
                'finished_at' => now(),
                'failure_message' => "Deployment timed out after {$minutes} minutes without a heartbeat.",
            ]);
        });
    }
}
