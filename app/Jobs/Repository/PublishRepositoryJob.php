<?php

namespace App\Jobs\Repository;

use App\Actions\Repository\PublishRepositoryAction;
use App\Models\Build;
use App\Services\Runner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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
        $started = Build::query()
            ->whereKey($this->build->id)
            ->where('status', Build::STATUS_QUEUED)
            ->update([
                'status' => Build::STATUS_DEPLOYING,
                'started_at' => now(),
                'failure_message' => null,
            ]);
        if ($started === 0) {
            return;
        }

        $this->build->refresh();
        $process = (new PublishRepositoryAction($this->build, $runner))->handle();

        Build::query()
            ->whereKey($this->build->id)
            ->where('status', Build::STATUS_DEPLOYING)
            ->update([
                'status' => Build::STATUS_RUNNING,
                'remote_process_id' => $process['id'],
                'remote_process_path' => $process['path'],
            ]);
    }

    public function failed(\Throwable $exception): void
    {
        Build::query()
            ->whereKey($this->build->id)
            ->whereIn('status', [Build::STATUS_QUEUED, Build::STATUS_DEPLOYING, Build::STATUS_RUNNING])
            ->update([
                'status' => Build::STATUS_FAILED,
                'remote_process_id' => null,
                'remote_process_path' => null,
                'finished_at' => now(),
                'failure_message' => str($exception->getMessage())->limit(2000),
            ]);
    }
}
