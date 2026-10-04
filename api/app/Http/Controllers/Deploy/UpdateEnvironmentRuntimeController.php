<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\SetEnvironmentRuntime;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UpdateEnvironmentRuntimeController
{
    /**
     * Hibernate or wake an environment now and return to its Automation tab.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Environment  $environment
     * @param  SetEnvironmentRuntime  $set
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Environment $environment, SetEnvironmentRuntime $set): JsonResponse
    {
        /** @var array{state: 'running'|'hibernated'} $data */
        $data = $request->validate(['state' => ['required', 'in:running,hibernated']]);
        $set->handle($user, $environment, $data['state']);

        return response()->json(['redirect' => route('deploy.environments.show', [$project, $environment, 'tab' => 'automation'], false), 'message' => $data['state'] === 'hibernated' ? __('Hibernating. Its websites go into maintenance mode shortly.') : __('Waking up. Workers start shortly.')]);
    }
}
