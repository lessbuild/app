<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Website;

class WebsitePolicy
{
    public function view(User $user, Website $website): bool
    {
        return $website->organization
            ? (int) $website->organization_id === (int) $user->current_organization_id
                && $website->organization->permits($user, 'view')
            : (int) $website->user_id === (int) $user->id;
    }

    public function update(User $user, Website $website): bool
    {
        return $this->view($user, $website)
            && ($website->organization?->permits($user, 'deploy') ?? true);
    }

    public function delete(User $user, Website $website): bool
    {
        return $this->view($user, $website)
            && ($website->organization?->permits($user, 'manage') ?? true);
    }
}
