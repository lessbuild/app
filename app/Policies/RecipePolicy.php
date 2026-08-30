<?php

namespace App\Policies;

use App\Models\Recipe;
use App\Models\User;

class RecipePolicy
{
    public function update(User $user, Recipe $recipe): bool
    {
        return (int) $recipe->user_id === (int) $user->id;
    }

    public function delete(User $user, Recipe $recipe): bool
    {
        return $this->update($user, $recipe);
    }
}
