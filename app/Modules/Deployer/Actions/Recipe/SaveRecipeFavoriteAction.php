<?php

namespace App\Modules\Deployer\Actions\Recipe;

use App\Modules\Deployer\Models\Recipe;
use App\Modules\Deployer\Models\RecipeFavorite;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\ActivityRecorder;

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
