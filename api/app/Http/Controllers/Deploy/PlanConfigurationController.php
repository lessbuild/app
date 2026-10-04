<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\PlanConfiguration;
use App\Http\Requests\Deploy\ConfigurationRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class PlanConfigurationController
{
    /**
     * Plan the posted configuration document and show the plan on the configuration page, keeping what was typed.
     *
     * @param  ConfigurationRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  PlanConfiguration  $plan
     * @return JsonResponse
     */
    public function __invoke(ConfigurationRequest $request, #[CurrentUser] User $user, Project $project, PlanConfiguration $plan): JsonResponse
    {
        // The plan is shown on the page; nothing changes until a review of it is applied.
        return response()->json(['plan' => $plan->handle($user, $project, $request->document(), $request->bindings())]);
    }
}
