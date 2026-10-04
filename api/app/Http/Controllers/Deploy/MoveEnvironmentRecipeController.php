<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\MoveEnvironmentRecipe;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MoveEnvironmentRecipeController
{
    /**
     * Move an environment recipe up or down the run order and return to the Recipes tab.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Environment  $environment
     * @param  string  $entry
     * @param  MoveEnvironmentRecipe  $move
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Environment $environment, string $entry, MoveEnvironmentRecipe $move): JsonResponse
    {
        /** @var array{direction: 'up'|'down'} $data */
        $data = $request->validate(['direction' => ['required', 'in:up,down']]);
        $move->handle($user, $environment->recipes()->findOrFail((int) $entry), $data['direction']);

        return response()->json(['redirect' => route('deploy.environments.show', [$project, $environment, 'tab' => 'recipes'], false)]);
    }
}
