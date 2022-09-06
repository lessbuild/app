<?php

namespace App\Observers;

use App\Jobs\Repository\PublishRepositoryJob;
use App\Models\Repository;

class RepositoryObserver
{
    /**
     * When a repository is created
     *
     * @param  \App\Models\Repository  $repository
     * @return void
     */
    public function created(Repository $repository)
    {
        PublishRepositoryJob::dispatch($repository);
    }

    /**
     * When a repository is deleted
     *
     * @param  \App\Models\Repository  $repository
     * @return void
     */
    public function deleting(Repository $repository)
    {
    }
}
