<?php

namespace App\Actions\Repository;

use App\Models\Repository;
use App\Models\Website;
use Illuminate\Support\Facades\DB;

class DeleteRepositoryAction
{
    /**
     * Soft-delete a repository only while its current website remains attached and deployment-free.
     *
     * @param  Repository  $repository  Repository whose placement and lifecycle state are being protected.
     * @return bool Whether the repository was deleted; false when its placement or website capacity is no longer valid.
     */
    public function handle(Repository $repository): bool
    {
        return DB::transaction(function () use ($repository): bool {
            $website = Website::query()->lockForUpdate()->findOrFail($repository->website_id);
            $locked = Repository::query()->lockForUpdate()->findOrFail($repository->id);
            if ((int) $locked->website_id !== (int) $website->id || $website->hasActiveDeployment()) {
                return false;
            }

            return (bool) $locked->delete();
        });
    }
}
