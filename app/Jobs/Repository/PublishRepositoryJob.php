<?php

namespace App\Jobs\Repository;

use App\Actions\Repository\PublishRepositoryAction;
use App\Models\Build;
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
     *
     * @var \App\Models\Build
     */
    public Build $build;

    /**
     * Create a new job instance.
     *
     * @param  \App\Models\Build  $build
     */
    public function __construct(Build $build)
    {
        $this->build = $build;
    }

    /**
     * Execute the job.
     *
     * @return void
     *
     * @throws \Exception
     */
    public function handle()
    {
        $this->build->update([
            'status' => Build::STATUS_DEPLOYING,
            'started_at' => now(),
            'failure_message' => null,
        ]);

        (new PublishRepositoryAction($this->build))->handle();

        $this->build->update(['status' => Build::STATUS_RUNNING]);
    }

    public function failed(\Throwable $exception): void
    {
        $this->build->update([
            'status' => Build::STATUS_FAILED,
            'finished_at' => now(),
            'failure_message' => str($exception->getMessage())->limit(2000),
        ]);
    }
}
