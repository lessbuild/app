<?php

declare(strict_types=1);

namespace App\Actions\Recipes;

use App\Enums\RecipeCategory;
use App\Models\Account;
use App\Models\Recipe;
use App\Models\User;
use App\Services\Deploy\RecipeRevisions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class SaveRecipe
{
    /**
     * Create a new SaveRecipe instance.
     *
     * Creates and edits recipes.
     *
     * @param  RecipeRevisions  $revisions  Keeps each saved version.
     */
    public function __construct(private readonly RecipeRevisions $revisions) {}

    /**
     * Create a recipe in the account, or edit one, keeping a revision. Editing a published recipe's content makes a new
     * gallery revision, which installed copies can refresh to.
     *
     * @param  User  $actor
     * @param  Account  $account
     * @param  Recipe|null  $recipe  null to create
     * @param  array{name: string, description: string|null, category: RecipeCategory, script: string}  $data
     * @return Recipe
     */
    public function handle(User $actor, Account $account, ?Recipe $recipe, array $data): Recipe
    {
        $recipe === null ? Gate::forUser($actor)->authorize('create', [Recipe::class, $account]) : Gate::forUser($actor)->authorize('update', $recipe);

        return DB::transaction(function () use ($actor, $account, $recipe, $data): Recipe {
            $record = $recipe ?? new Recipe;
            $record->forceFill(['account_id' => $recipe->account_id ?? $account->id, 'created_by' => $recipe->created_by ?? $actor->id, ...$data]);
            $changed = $record->isDirty(['name', 'description', 'script']);
            if ($recipe !== null && ! $changed && ! $record->isDirty()) {
                return $record;
            }
            if ($record->is_published && $changed) {
                $record->gallery_revision_at = now()->toImmutable();
            }
            $record->save();
            if ($recipe === null || $changed) {
                $this->revisions->record($record, $actor, $recipe === null ? 'created' : 'edited');
            }

            return $record;
        });
    }
}
