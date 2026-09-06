<?php

namespace App\Policies;

use App\Models\Recipe;
use App\Models\User;

class RecipePolicy
{
    public function view(User $user, Recipe $recipe): bool
    {
        return $recipe->organization
            ? (int) $recipe->organization_id === (int) $user->current_organization_id
                && $recipe->organization->permits($user, 'view')
            : (int) $recipe->user_id === (int) $user->id;
    }

    public function update(User $user, Recipe $recipe): bool
    {
        return $this->view($user, $recipe)
            && ($recipe->organization?->permits($user, 'deploy') ?? true);
    }

    public function delete(User $user, Recipe $recipe): bool
    {
        return $this->view($user, $recipe)
            && ($recipe->organization?->permits($user, 'manage') ?? true);
    }
}
