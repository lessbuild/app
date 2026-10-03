<?php

declare(strict_types=1);

namespace App\Actions\Recipes;

use App\Models\Account;
use App\Models\Recipe;
use App\Models\User;
use App\Services\Deploy\RecipeRevisions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class InstallRecipe
{
    /**
     * Create a new InstallRecipe instance.
     *
     * Installs gallery recipes.
     *
     * @param  RecipeRevisions  $revisions  Starts the copy's history.
     */
    public function __construct(private readonly RecipeRevisions $revisions) {}

    /**
     * Copy a published recipe into the account, unpublished and linked to its source revision, counting one install.
     * An account installs a recipe once: installing again returns the existing copy.
     *
     * @param  User  $actor
     * @param  Account  $account
     * @param  Recipe  $source
     * @return Recipe
     */
    public function handle(User $actor, Account $account, Recipe $source): Recipe
    {
        Gate::forUser($actor)->authorize('viewPublished', $source);
        Gate::forUser($actor)->authorize('create', [Recipe::class, $account]);

        return DB::transaction(function () use ($actor, $account, $source): Recipe {
            $locked = Recipe::query()->published()->lockForUpdate()->findOrFail($source->id);
            $existing = Recipe::query()->where('account_id', $account->id)->where('source_recipe_id', $locked->id)->first();
            if ($existing !== null) {
                return $existing;
            }
            $copy = new Recipe;
            $copy->forceFill([
                'account_id' => $account->id, 'created_by' => $actor->id, 'name' => $locked->name, 'description' => $locked->description,
                'category' => $locked->category, 'script' => $locked->script, 'source_recipe_id' => $locked->id, 'source_revision_at' => $locked->gallery_revision_at,
            ])->save();
            $locked->increment('install_count');
            $this->revisions->record($copy, $actor, 'installed');

            return $copy;
        });
    }
}
