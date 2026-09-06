<?php

namespace App\Policies;

use App\Models\Repository;
use App\Models\User;

class RepositoryPolicy
{
    public function view(User $user, Repository $repository): bool
    {
        return $repository->organization
            ? (int) $repository->organization_id === (int) $user->current_organization_id
                && $repository->organization->permits($user, 'view')
            : (int) $repository->user_id === (int) $user->id;
    }

    public function update(User $user, Repository $repository): bool
    {
        return $this->view($user, $repository)
            && ($repository->organization?->permits($user, 'deploy') ?? true);
    }

    public function delete(User $user, Repository $repository): bool
    {
        return $this->view($user, $repository)
            && ($repository->organization?->permits($user, 'manage') ?? true);
    }

    public function deploy(User $user, Repository $repository): bool
    {
        return $this->update($user, $repository);
    }
}
