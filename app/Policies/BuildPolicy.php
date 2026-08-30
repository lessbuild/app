<?php

namespace App\Policies;

use App\Models\Build;
use App\Models\User;

class BuildPolicy
{
    public function view(User $user, Build $build): bool
    {
        return (int) $build->repository?->user_id === (int) $user->id;
    }

    public function cancel(User $user, Build $build): bool
    {
        return $this->view($user, $build);
    }
}
