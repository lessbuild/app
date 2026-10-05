<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Build;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/** Builds follow their repository; approving needs deploy rights and someone other than whoever asked for the deploy. */
final class BuildPolicy
{
    /**
     * Determine whether the user can see a deploy and its log: whoever may see its repository.
     *
     * @param  User  $user
     * @param  Build  $build
     * @return bool
     */
    public function view(User $user, Build $build): bool
    {
        return $user->can('view', $build->repository);
    }

    /**
     * Determine whether the user can write the team's note on a deploy: whoever may deploy its repository.
     *
     * @param  User  $user
     * @param  Build  $build
     * @return bool
     */
    public function note(User $user, Build $build): bool
    {
        return $user->can('deploy', $build->repository);
    }

    /**
     * Determine whether the user can approve or reject a deploy that waits for approval: someone who may deploy the
     * repository, and not the person who asked for the deploy.
     *
     * @param  User  $user
     * @param  Build  $build
     * @return Response
     */
    public function approve(User $user, Build $build): Response
    {
        if (! $user->can('deploy', $build->repository)) {
            return Response::deny();
        }

        return $build->requested_by === $user->id ? Response::deny(__('Someone else has to approve your deploy.')) : Response::allow();
    }
}
