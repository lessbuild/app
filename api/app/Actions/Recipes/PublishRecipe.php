<?php

declare(strict_types=1);

namespace App\Actions\Recipes;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class PublishRecipe
{
    /**
     * Share a recipe in the gallery with every account, or take it out. Publishing starts a new gallery revision when
     * the recipe changed since it was last published, so copies installed earlier see an update; unpublishing leaves
     * installed copies as they are.
     *
     * @param  User  $actor
     * @param  Recipe  $recipe
     * @param  bool  $published
     * @return void
     */
    public function handle(User $actor, Recipe $recipe, bool $published): void
    {
        Gate::forUser($actor)->authorize('publish', $recipe);
        if ($recipe->is_published === $published) {
            return;
        }
        $lastSaved = $recipe->revisions()->first()?->created_at;
        $changed = $recipe->gallery_revision_at === null || ($lastSaved !== null && $lastSaved->gt($recipe->gallery_revision_at));
        $recipe->forceFill($published
            ? ['is_published' => true, 'published_at' => $recipe->published_at ?? now(), 'gallery_revision_at' => $changed ? now() : $recipe->gallery_revision_at]
            : ['is_published' => false])->save();
    }
}
