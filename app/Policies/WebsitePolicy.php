<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Website;

class WebsitePolicy
{
    public function view(User $user, Website $website): bool
    {
        return (int) $website->user_id === (int) $user->id;
    }

    public function update(User $user, Website $website): bool
    {
        return $this->view($user, $website);
    }

    public function delete(User $user, Website $website): bool
    {
        return $this->view($user, $website);
    }
}
