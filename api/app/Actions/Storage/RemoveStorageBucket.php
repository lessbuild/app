<?php

declare(strict_types=1);

namespace App\Actions\Storage;

use App\Models\StorageBucket;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class RemoveStorageBucket
{
    /**
     * Forget a bucket and its keys. The bucket and its files stay at the storage service, and environment variables
     * already set are left as they are.
     *
     * @param  User  $actor
     * @param  StorageBucket  $bucket
     * @return void
     */
    public function handle(User $actor, StorageBucket $bucket): void
    {
        Gate::forUser($actor)->authorize('manageService', [$bucket->project, 'infrastructure']);
        $bucket->delete();
    }
}
