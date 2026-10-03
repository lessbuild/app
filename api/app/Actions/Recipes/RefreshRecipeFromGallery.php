<?php

declare(strict_types=1);

namespace App\Actions\Recipes;

use App\Exceptions\StateConflict;
use App\Models\Recipe;
use App\Models\User;
use App\Services\Deploy\RecipeRevisions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class RefreshRecipeFromGallery
{
    /**
     * Create a new RefreshRecipeFromGallery instance.
     *
     * Updates installed copies from the gallery.
     *
     * @param  RecipeRevisions  $revisions  Records the refresh, so local edits it replaces stay in the history.
     */
    public function __construct(private readonly RecipeRevisions $revisions) {}

    /**
     * Replace an installed copy's name, description, category and script with its gallery source's latest revision.
     * The source must still be published.
     *
     * @param  User  $actor
     * @param  Recipe  $copy
     * @return void
     */
    public function handle(User $actor, Recipe $copy): void
    {
        Gate::forUser($actor)->authorize('update', $copy);
        DB::transaction(function () use ($actor, $copy): void {
            $source = $copy->source_recipe_id === null ? null : Recipe::query()->published()->find($copy->source_recipe_id);
            if ($source === null) {
                throw new StateConflict(__('This recipe’s gallery original isn’t published any more.'));
            }
            $copy->forceFill([
                'name' => $source->name, 'description' => $source->description, 'category' => $source->category, 'script' => $source->script,
                'source_revision_at' => $source->gallery_revision_at,
            ])->save();
            $this->revisions->record($copy, $actor, 'refreshed');
        });
    }
}
