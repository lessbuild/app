<?php

namespace App\Policies;

use App\Models\Recipe;
use App\Models\User;

class RecipePolicy
{
    public function view(User $user, Recipe $recipe): bool
    {
        return (int) $recipe->user_id === (int) $user->id;
    }

    public function update(User $user, Recipe $recipe): bool
    {
        return $this->view($user, $recipe);
    }

    public function delete(User $user, Recipe $recipe): bool
    {
        return $this->update($user, $recipe);
    }
}
