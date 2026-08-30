<?php

namespace App\Policies;

use App\Models\Repository;
use App\Models\User;

class RepositoryPolicy
{
    public function view(User $user, Repository $repository): bool
    {
        return (int) $repository->user_id === (int) $user->id;
    }

    public function update(User $user, Repository $repository): bool
    {
        return $this->view($user, $repository);
    }

    public function delete(User $user, Repository $repository): bool
    {
        return $this->view($user, $repository);
    }

    public function deploy(User $user, Repository $repository): bool
    {
        return $this->view($user, $repository);
    }
}
