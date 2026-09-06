<?php

namespace App\Jobs\Repository;

use App\Actions\Repository\PublishRepositoryAction;
use App\Models\Build;
use App\Services\ApplicationConfigurationExecution;
use App\Services\AutomaticDeploymentRollback;
use App\Services\Runner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class PublishRepositoryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The build instance.
     */
    public Build $build;

    /**
     * Create a new job instance.
     */
    public function __construct(Build $build)
    {
        $this->build = $build;
    }

    /**
     * Execute the job.
     *
     *
     * @throws \Exception
     */
    public function handle(Runner $runner): void
    {
        $this->build->refresh();
        $started = app(ApplicationConfigurationExecution::class)->claim($this->build);
        if ($started === null) {
            $releaseName = $this->build->releaseIdentifier();
            $releasePath = "/var/www/{$this->build->repository->website->deployment_slug}/releases/{$releaseName}";
            $started = Build::query()->whereKey($this->build->id)->where('status', Build::STATUS_QUEUED)
                ->update([
                    'status' => Build::STATUS_DEPLOYING,
                    'started_at' => now(),
                    'last_heartbeat_at' => now(),
                    'remote_process_path' => "/tmp/lessbuild-deployment-{$this->build->id}.sh",
                    'failure_message' => null,
                    'release_name' => $releaseName,
                    'release_path' => $releasePath,
                ]) === 1;
        }
        if (! $started) {
            return;
        }

        $this->build->refresh();
        $process = (new PublishRepositoryAction($this->build, $runner))->handle();

        $running = Build::query()
            ->whereKey($this->build->id)
            ->where('status', Build::STATUS_DEPLOYING)
            ->update([
                'status' => Build::STATUS_RUNNING,
                'remote_process_id' => $process['id'],
                'remote_process_path' => $process['path'],
            ]);
        if ($running === 0) {
            Build::query()
                ->whereKey($this->build->id)
                ->whereIn('status', Build::TERMINAL_STATUSES)
                ->update([
                    'remote_process_id' => null,
                    'remote_process_path' => null,
                ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        DB::transaction(function () use ($exception): void {
            $locked = Build::query()
                ->whereKey($this->build->id)
                ->whereIn('status', [Build::STATUS_QUEUED, Build::STATUS_DEPLOYING, Build::STATUS_RUNNING])
                ->lockForUpdate()
                ->first();

            $locked?->update([
                'status' => Build::STATUS_FAILED,
                'remote_process_id' => null,
                'remote_process_path' => null,
                'finished_at' => now(),
                'failure_message' => str($exception->getMessage())->limit(2000),
            ]);
        });

        app(AutomaticDeploymentRollback::class)->attempt($this->build->fresh());
    }
}
