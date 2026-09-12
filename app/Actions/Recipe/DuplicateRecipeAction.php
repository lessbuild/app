<?php

namespace App\Actions\Recipe;

use App\Models\Recipe;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Support\Str;

class DuplicateRecipeAction
{
    public function __construct(private readonly ActivityRecorder $activity) {}

    /**
     * Create an unassigned private workspace copy and record the source-to-copy activity.
     *
     * @param  User  $owner  Actor and workspace receiving the copy.
     * @param  Recipe  $source  Recipe whose encrypted script and metadata are copied.
     * @return Recipe The unassigned private copy.
     */
    public function handle(User $owner, Recipe $source): Recipe
    {
        $copy = $owner->workspaceRecipes()->create([
            'name' => Str::of("Copy of {$source->name}")->limit(255, '')->toString(),
            'description' => $source->description,
            'script' => $source->script,
        ]);
        $this->activity->record(
            $copy,
            $owner->id,
            'recipe',
            "Recipe \"{$source->name}\" was duplicated as \"{$copy->name}\".",
        );

        return $copy;
    }
}
