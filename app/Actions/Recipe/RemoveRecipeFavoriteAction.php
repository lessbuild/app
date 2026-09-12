<?php

namespace App\Actions\Recipe;

use App\Models\Recipe;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class RemoveRecipeFavoriteAction
{
    public function __construct(private readonly ActivityRecorder $activity) {}

    /**
     * Remove only the actor's existing favorite and record its removal.
     *
     * @throws ModelNotFoundException When the actor has no favorite for the recipe.
     */
    public function handle(Recipe $recipe, User $user): void
    {
        $user->recipeFavorites()
            ->where('recipe_id', $recipe->id)
            ->firstOrFail()
            ->delete();
        $this->activity->record(
            $recipe,
            $user->id,
            'recipe',
            "Gallery recipe \"{$recipe->name}\" was removed from saved recipes.",
        );
    }
}
