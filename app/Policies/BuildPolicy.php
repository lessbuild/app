<?php

namespace App\Policies;

use App\Models\Build;
use App\Models\User;

class BuildPolicy
{
    public function view(User $user, Build $build): bool
    {
        $repository = $build->repository;

        return $repository?->organization
            ? (int) $repository->organization_id === (int) $user->current_organization_id
                && $repository->organization->permits($user, 'view')
            : (int) $repository?->user_id === (int) $user->id;
    }

    public function cancel(User $user, Build $build): bool
    {
        return $this->view($user, $build)
            && ($build->repository?->organization?->permits($user, 'deploy') ?? true);
    }

    public function redeploy(User $user, Build $build): bool
    {
        return $this->cancel($user, $build);
    }

    public function approve(User $user, Build $build): bool
    {
        return $this->view($user, $build)
            && ($build->repository?->organization?->permits($user, 'manage') ?? false);
    }

    public function rollback(User $user, Build $build): bool
    {
        return $this->approve($user, $build);
    }

    public function updateNote(User $user, Build $build): bool
    {
        return $this->cancel($user, $build);
    }
}
