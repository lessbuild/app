<?php

namespace App\Actions\Recipe;

use App\Models\Recipe;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class InstallGalleryRecipeAction
{
    public function __construct(private readonly ActivityRecorder $activity) {}

    /**
     * Lock a published gallery source, create at most one private snapshot, and increment its install count once.
     *
     * @param  User  $user  Account receiving the private recipe copy.
     * @param  Recipe  $source  Route-bound gallery source whose published state is rechecked under the lock.
     * @return Recipe The existing or newly created private copy.
     *
     * @throws ModelNotFoundException When the source is not currently published.
     */
    public function handle(User $user, Recipe $source): Recipe
    {
        $copy = DB::transaction(function () use ($source, $user): Recipe {
            $lockedSource = Recipe::query()
                ->published()
                ->lockForUpdate()
                ->findOrFail($source->id);

            $existing = $user->workspaceRecipes()
                ->where('source_recipe_id', $lockedSource->id)
                ->latest('id')
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            $copy = $user->workspaceRecipes()->create([
                'name' => $lockedSource->name,
                'description' => $lockedSource->description,
                'script' => $lockedSource->script,
                'source_recipe_id' => $lockedSource->id,
                'source_revision_at' => $lockedSource->gallery_revision_at,
                'is_published' => false,
            ]);

            $lockedSource->increment('install_count');

            return $copy;
        });

        if ($copy->wasRecentlyCreated) {
            $this->activity->record(
                $copy,
                $user->id,
                'recipe',
                "Gallery recipe \"{$copy->name}\" was installed as a private copy.",
            );
        }

        return $copy;
    }
}
