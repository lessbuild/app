<?php

namespace App\Actions\Repository;

use App\Models\Repository;
use App\Models\User;
use App\Models\Website;
use App\Services\RepositoryWebhookConfiguration;
use Illuminate\Support\Facades\DB;

class UpdateRepositoryAction
{
    public function __construct(private readonly RepositoryWebhookConfiguration $webhooks) {}

    /**
     * Persist a repository update only while its current website remains attached and deployment-free.
     *
     * @param  Repository  $repository  Repository whose current placement is being protected.
     * @param  User  $owner  Actor whose current workspace supplies the selected provider.
     * @param  array<string, mixed>  $attributes  Validated and request-normalized repository attributes.
     * @return bool Whether the repository was updated; false when its placement or website capacity is no longer valid.
     */
    public function handle(Repository $repository, User $owner, array $attributes): bool
    {
        $provider = $owner->workspaceProviders()->findOrFail($attributes['provider_id']);
        $repository->loadMissing('provider');
        $attributes = $this->webhooks->forUpdate($repository, $provider, $attributes);

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
