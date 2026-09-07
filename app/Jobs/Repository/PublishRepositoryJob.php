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
     * Capture the build to claim and launch when the queue worker runs.
     *
     * Create a new job instance.
     *
     * @param  Build  $build  Build record whose persisted deployment state and relationships are used by this operation.
     */
    public function __construct(Build $build)
    {
        $this->build = $build;
    }

    /**
     * Claim the queued deployment, start its remote script, and store the process identity without overwriting a terminal callback; skip builds whose claim is no longer valid.
     *
     * Execute the job.
     *
     * @param  Runner  $runner  SSH runner used to execute commands on the selected managed server.
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

    /**
     * Mark a still-active deployment failed, clear its remote identity, and ask the automatic rollback service to evaluate recovery.
     *
     * @param  \Throwable  $exception  Failure delivered by the queue after this job cannot complete successfully.
     */
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
