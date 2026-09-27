<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Build;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/** Builds follow their repository; approving needs deploy rights and someone other than whoever asked for the deploy. */
final class BuildPolicy
{
    public function view(User $user, Build $build): bool
    {
        return $user->can('view', $build->repository);
    }

    public function approve(User $user, Build $build): Response
    {
        if (! $user->can('deploy', $build->repository)) {
            return Response::deny();
        }

        return $build->requested_by === $user->id ? Response::deny(__('Someone else has to approve your deploy.')) : Response::allow();
    }
}
