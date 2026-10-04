<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\AddEnvironmentRecipe;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StoreEnvironmentRecipeController
{
    /**
     * Add a library recipe to the environment and return to its Recipes tab.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Environment  $environment
     * @param  AddEnvironmentRecipe  $add
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Environment $environment, AddEnvironmentRecipe $add): JsonResponse
    {
        $request->validate(['recipe_id' => ['required', 'integer']]);
        $add->handle($user, $environment, $request->integer('recipe_id'));

        return response()->json(['redirect' => route('deploy.environments.show', [$project, $environment, 'tab' => 'recipes'], false), 'message' => __('Recipe added.')]);
    }
}
