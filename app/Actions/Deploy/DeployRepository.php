<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Exceptions\StateConflict;
use App\Models\Build;
use App\Models\Repository;
use App\Models\User;
use App\Services\Deploy\Deployments;
use App\Support\GitRef;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class DeployRepository
{
    /**
     * Create a new DeployRepository instance.
     *
     * Starts a deploy of a repository by hand or through the API.
     *
     * @param  Deployments  $deployments  Queues the deploy, or says why it can't be queued now.
     */
    public function __construct(private readonly Deployments $deployments) {}

    /**
     * Deploy the branch's latest commit, a given commit on it (`$revision`, a full SHA), or any branch, tag or commit
     * someone names (`$ref`), which the server resolves when it checks the code out.
     *
     * @param  User  $actor
     * @param  Repository  $repository
     * @param  string|null  $revision
     * @param  string  $trigger
     * @param  string|null  $ref
     * @return Build
     */
    public function handle(User $actor, Repository $repository, ?string $revision = null, string $trigger = 'manual', ?string $ref = null): Build
    {
        Gate::forUser($actor)->authorize('deploy', $repository);
        if (! $repository->isDeploymentReady()) {
            throw ValidationException::withMessages(['deploy' => __('The website must be live on an active server, and the repository’s provider must host its address.')]);
        }
        $blocked = $this->deployments->blockReason($repository);
        if ($blocked !== null) {
            throw ValidationException::withMessages(['deploy' => $blocked]);
        }
        $gitRef = null;
        if ($ref !== null && trim($ref) !== '') {
            $gitRef = GitRef::normalize($ref) ?? throw ValidationException::withMessages(['ref' => __('Enter a branch, tag or commit, such as main, v1.2.0 or 3f2a9c1.')]);
        }
        $build = $this->deployments->queue($repository, ['trigger_source' => $trigger, 'revision' => $revision === null ? null : strtolower($revision), 'git_ref' => $gitRef], $actor);

        return $build ?? throw new StateConflict(__('A deploy to this website is already running.'));
    }
}
