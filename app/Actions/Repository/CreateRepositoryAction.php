<?php

namespace App\Actions\Repository;

use App\Models\Repository;
use App\Models\User;
use App\Services\RepositoryWebhookConfiguration;

class CreateRepositoryAction
{
    public function __construct(private readonly RepositoryWebhookConfiguration $webhooks) {}

    /**
     * Create a tenant-scoped repository with any required GitHub App webhook configuration.
     *
     * @param  User  $owner  Actor whose current workspace owns the repository.
     * @param  array<string, mixed>  $attributes  Validated and request-normalized repository attributes.
     * @return Repository The newly created repository.
     */
    public function handle(User $owner, array $attributes): Repository
    {
        $provider = $owner->workspaceProviders()->findOrFail($attributes['provider_id']);
        $attributes = $this->webhooks->forCreation($provider, $attributes);

        return $owner->workspaceRepositories()->create($attributes);
    }
}
