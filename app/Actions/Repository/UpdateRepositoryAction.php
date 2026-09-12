<?php

namespace App\Actions\Repository;

use App\Models\Repository;
use App\Models\Website;
use Illuminate\Support\Facades\DB;

class UpdateRepositoryAction
{
    /**
     * Persist a repository update only while its current website remains attached and deployment-free.
     *
     * @param  Repository  $repository  Repository whose current placement is being protected.
     * @param  array<string, mixed>  $attributes  Validated and request-normalized repository attributes.
     * @return bool Whether the repository was updated; false when its placement or website capacity is no longer valid.
     */
    public function handle(Repository $repository, array $attributes): bool
    {
        return DB::transaction(function () use ($repository, $attributes): bool {
            $website = Website::query()->lockForUpdate()->findOrFail($repository->website_id);
            $locked = Repository::query()->lockForUpdate()->findOrFail($repository->id);
            if ((int) $locked->website_id !== (int) $website->id || $website->hasActiveDeployment()) {
                return false;
            }

            $locked->update($attributes);

            return true;
        });
    }
}
