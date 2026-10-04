<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\UpdateEnvironmentHibernation;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UpdateEnvironmentHibernationController
{
    /**
     * Set or clear an environment's idle time before hibernating and return to its Automation tab.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Environment  $environment
     * @param  UpdateEnvironmentHibernation  $update
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Environment $environment, UpdateEnvironmentHibernation $update): JsonResponse
    {
        $request->validate(['hibernate_after_minutes' => ['nullable', 'integer']]);
        $update->handle($user, $environment, $request->filled('hibernate_after_minutes') ? $request->integer('hibernate_after_minutes') : null);

        return response()->json(['redirect' => route('deploy.environments.show', [$project, $environment, 'tab' => 'automation'], false), 'message' => __('Hibernation saved.')]);
    }
}
