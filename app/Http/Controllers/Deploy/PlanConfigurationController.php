<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\PlanConfiguration;
use App\Http\Requests\Deploy\ConfigurationRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class PlanConfigurationController
{
    public function __invoke(ConfigurationRequest $request, #[CurrentUser] User $user, Project $project, PlanConfiguration $plan): RedirectResponse
    {
        return to_route('deploy.configuration', $project)->withInput()->with('plan', $plan->handle($user, $project, $request->document(), $request->bindings()));
    }
}
