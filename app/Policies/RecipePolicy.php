<?php

namespace App\Policies;

use App\Models\Recipe;
use App\Models\User;

class RecipePolicy
{
    /**
     * Allow the recipe owner or a viewer in its currently selected organization.
     *
     * @param  User  $user  Account requesting the ability in its current organization.
     * @param  Recipe  $recipe  Resource whose ownership and organization permissions are checked.
     * @return bool Whether the account is authorized; lifecycle eligibility is checked by the action.
     */
    public function view(User $user, Recipe $recipe): bool
    {
        return $recipe->organization
            ? (int) $recipe->organization_id === (int) $user->current_organization_id
                && $recipe->organization->permits($user, 'view')
            : (int) $recipe->user_id === (int) $user->id;
    }

    /**
     * Allow the recipe owner or an organization deployer who can view it.
     *
     * @param  User  $user  Account requesting the ability in its current organization.
     * @param  Recipe  $recipe  Resource whose ownership and organization permissions are checked.
     * @return bool Whether the account is authorized; lifecycle eligibility is checked by the action.
     */
    public function update(User $user, Recipe $recipe): bool
    {
        return $this->view($user, $recipe)
            && ($recipe->organization?->permits($user, 'deploy') ?? true);
    }

    /**
     * Allow the recipe owner or an organization manager who can view it.
     *
     * @param  User  $user  Account requesting the ability in its current organization.
     * @param  Recipe  $recipe  Resource whose ownership and organization permissions are checked.
     * @return bool Whether the account is authorized; lifecycle eligibility is checked by the action.
     */
    public function delete(User $user, Recipe $recipe): bool
    {
        return $this->view($user, $recipe)
            && ($recipe->organization?->permits($user, 'manage') ?? true);
    }
}
