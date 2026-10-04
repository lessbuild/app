<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Data\Infrastructure\ServerSummary;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Recipe;
use App\Models\User;
use App\Queries\Infrastructure\ServerCreateFormQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CreateServerController
{
    /**
     * Describe the new server form: the account's cloud providers and, for the chosen one (`?provider=`), its regions,
     * sizes and Ubuntu images; the server types; and the recipes that can run at the end of provisioning.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  ServerCreateFormQuery  $form
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ServerCreateFormQuery $form): JsonResponse
    {
        $provider = $request->query('provider');
        $data = $form->handle($project, is_string($provider) ? $provider : null);
        /** @var iterable<Provider> $providers */
        $providers = $data['providers'];
        /** @var iterable<Recipe> $recipes */
        $recipes = $data['recipes'];
        $selected = $data['provider'] instanceof Provider ? $data['provider'] : null;

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'providers' => collect($providers)->map(fn (Provider $option): array => ['value' => (string) $option->id, 'label' => $option->name.' · '.$option->type->label()])->values(),
            'providerId' => $selected === null ? null : (string) $selected->id,
            'catalog' => $data['catalog'],
            'catalogError' => $data['catalogError'],
            'types' => ServerSummary::types(),
            'recipes' => collect($recipes)->map(fn (Recipe $recipe): array => ['id' => $recipe->id, 'name' => $recipe->name, 'description' => $recipe->description])->values(),
        ]);
    }
}
