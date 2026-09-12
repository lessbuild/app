<?php

namespace App\Actions\Recipe;

use App\Models\Recipe;
use App\Models\RecipeFavorite;
use App\Models\User;
use App\Services\ActivityRecorder;

class SaveRecipeFavoriteAction
{
    public function __construct(private readonly ActivityRecorder $activity) {}

    /**
     * Save a published recipe to the actor's favorites idempotently and record only a new favorite.
     *
     * @return RecipeFavorite The existing or newly created actor-owned favorite.
     */
    public function handle(Recipe $recipe, User $user): RecipeFavorite
    {
        $favorite = $user->recipeFavorites()->firstOrCreate([
            'recipe_id' => $recipe->id,
        ]);

        if ($favorite->wasRecentlyCreated) {
            $this->activity->record(
                $recipe,
                $user->id,
                'recipe',
                "Gallery recipe \"{$recipe->name}\" was saved.",
            );
        }

        return $favorite;
    }
}
