<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccountPermission;
use App\Models\Account;
use App\Models\Membership;
use App\Models\Recipe;
use App\Models\User;
use App\Policies\Concerns\ChecksAccountRole;

/**
 * Recipes run on servers, so they follow Infrastructure access: anyone with it sees the account's recipes and the
 * gallery, members and above change them, and owners and admins publish them (and answer their reports), since
 * publishing shares a script with every account.
 */
final class RecipePolicy
{
    use ChecksAccountRole;

    /**
     * Determine whether the user can see their current account's recipes and browse the gallery.
     *
     * @param  User  $user
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        return $user->current_account_id !== null && $this->allows($user, $user->current_account_id, AccountPermission::ViewProjects, 'infrastructure');
    }

    /**
     * Determine whether the user can see a recipe of their account, script and history included.
     *
     * @param  User  $user
     * @param  Recipe  $recipe
     * @return bool
     */
    public function view(User $user, Recipe $recipe): bool
    {
        return $this->allows($user, $recipe->account_id, AccountPermission::ViewProjects, 'infrastructure');
    }

    /**
     * Determine whether the user can add recipes to an account, including by installing from the gallery.
     *
     * @param  User  $user
     * @param  Account  $account
     * @return bool
     */
    public function create(User $user, Account $account): bool
    {
        return $this->allows($user, $account->id, AccountPermission::ManageProjects, 'infrastructure');
    }

    /**
     * Determine whether the user can edit, duplicate or refresh a recipe of their account.
     *
     * @param  User  $user
     * @param  Recipe  $recipe
     * @return bool
     */
    public function update(User $user, Recipe $recipe): bool
    {
        return $this->allows($user, $recipe->account_id, AccountPermission::ManageProjects, 'infrastructure');
    }

    /**
     * Determine whether the user can delete a recipe, which the same people as update can.
     *
     * @param  User  $user
     * @param  Recipe  $recipe
     * @return bool
     */
    public function delete(User $user, Recipe $recipe): bool
    {
        return $this->update($user, $recipe);
    }

    /**
     * Determine whether the user can publish a recipe to the gallery, unpublish it, and resolve its reports: owners and
     * admins of its account.
     *
     * @param  User  $user
     * @param  Recipe  $recipe
     * @return bool
     */
    public function publish(User $user, Recipe $recipe): bool
    {
        return $this->allows($user, $recipe->account_id, AccountPermission::ManageSettings, 'infrastructure');
    }

    /**
     * Determine whether the user can open a gallery recipe: it's published and they can browse the gallery.
     *
     * @param  User  $user
     * @param  Recipe  $recipe
     * @return bool
     */
    public function viewPublished(User $user, Recipe $recipe): bool
    {
        return $recipe->is_published && $this->viewAny($user);
    }

    /**
     * Determine whether the user can rate a gallery recipe: their current account has installed it and they're not in
     * the publishing account.
     *
     * @param  User  $user
     * @param  Recipe  $recipe
     * @return bool
     */
    public function rate(User $user, Recipe $recipe): bool
    {
        return $this->viewPublished($user, $recipe) && ! $this->isMemberOf($user, $recipe->account_id)
            && Recipe::query()->where('account_id', $user->current_account_id)->where('source_recipe_id', $recipe->id)->exists();
    }

    /**
     * Determine whether the user can report a gallery recipe: anyone who can open it outside the publishing account.
     *
     * @param  User  $user
     * @param  Recipe  $recipe
     * @return bool
     */
    public function report(User $user, Recipe $recipe): bool
    {
        return $this->viewPublished($user, $recipe) && ! $this->isMemberOf($user, $recipe->account_id);
    }

    /**
     * Determine whether the user belongs to an account.
     *
     * @param  User  $user
     * @param  string  $accountId
     * @return bool
     */
    private function isMemberOf(User $user, string $accountId): bool
    {
        return Membership::query()->where('account_id', $accountId)->where('user_id', $user->id)->exists();
    }
}
