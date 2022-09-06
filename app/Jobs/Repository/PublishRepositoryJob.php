<?php

namespace App\Jobs\Repository;

use App\Actions\Repository\PublishRepositoryAction;
use App\Models\Repository;
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
     * The repository instance.
     *
     * @var \App\Models\Repository
     */
    public Repository $repository;

    /**
     * Create a new job instance.
     *
     * @param \App\Models\Repository $repository
     */
    public function __construct(Repository $repository)
    {
        $this->repository = $repository;
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
        (new PublishRepositoryAction($this->repository))->handle();
    }
}
