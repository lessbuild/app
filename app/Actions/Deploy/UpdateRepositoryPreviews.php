<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\Repository;
use App\Models\User;
use App\Models\Website;
use App\Services\Billing\Entitlements;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class UpdateRepositoryPreviews
{
    /**
     * Create a new UpdateRepositoryPreviews instance.
     *
     * Changes a repository's preview settings.
     *
     * @param  Entitlements  $entitlements  Checks the plan includes previews.
     */
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Turn pull-request previews on or off and set their domain, lifetime and initialisation command. Turning them on
     * needs `deploy.previews`; turning them off leaves open previews to close or expire.
     *
     * @param  User  $actor
     * @param  Repository  $repository
     * @param  array{previews_enabled: bool, preview_domain: string|null, preview_ttl_hours: int, preview_initialization_command: string|null, preview_database_source_website_id?: int|null}  $data
     * @return void
     */
    public function handle(User $actor, Repository $repository, array $data): void
    {
        Gate::forUser($actor)->authorize('update', $repository);
        if ($data['previews_enabled'] && ! $this->entitlements->for($repository->project->account)->has('deploy.previews')) {
            throw ValidationException::withMessages(['previews_enabled' => __('Previews come with the Pro Deploy plan and above.')]);
        }
        $source = $data['preview_database_source_website_id'] ?? null;
        if ($source !== null) {
            $website = Website::query()->find($source);
            if ($website === null || $website->server_id !== $repository->website->server_id || $website->is($repository->website)) {
                throw ValidationException::withMessages(['preview_database_source_website_id' => __('Choose another website on the same server as this repository’s website.')]);
            }
            Gate::forUser($actor)->authorize('manageDatabase', $website);
        }
        $repository->forceFill($data)->save();
    }
}
