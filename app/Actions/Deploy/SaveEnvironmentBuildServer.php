<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\Environment;
use App\Models\Server;
use App\Models\StorageBucket;
use App\Models\User;
use App\Services\Deploy\BuildServers;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SaveEnvironmentBuildServer
{
    /**
     * Choose where an environment's deploys build: on one of the account's app or worker servers, passing the built
     * release to the websites through one of the project's storage buckets, or (with neither) on each website's
     * server as before. Applies from the next deploy.
     *
     * @param  User  $actor
     * @param  Environment  $environment
     * @param  Server|null  $server
     * @param  StorageBucket|null  $bucket
     * @return void
     */
    public function handle(User $actor, Environment $environment, ?Server $server, ?StorageBucket $bucket): void
    {
        Gate::forUser($actor)->authorize('configureDeploy', $environment);
        if (($server === null) !== ($bucket === null)) {
            throw ValidationException::withMessages(['build_server_id' => __('Choose both a build server and a storage bucket, or neither.')]);
        }
        if ($server !== null && ($server->account_id !== $environment->project->account_id || ! in_array($server->type, BuildServers::TYPES, true))) {
            throw ValidationException::withMessages(['build_server_id' => __('Choose one of this account’s app or worker servers.')]);
        }
        if ($bucket !== null && $bucket->project_id !== $environment->project_id) {
            throw ValidationException::withMessages(['artifact_bucket_id' => __('Choose one of this project’s storage buckets.')]);
        }
        $environment->forceFill(['build_server_id' => $server?->id, 'artifact_bucket_id' => $bucket?->id])->save();
    }
}
