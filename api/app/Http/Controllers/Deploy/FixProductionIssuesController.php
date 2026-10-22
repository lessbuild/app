<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\FixProductionIssues;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FixProductionIssuesController
{
    /**
     * Apply one production check fix (key given) or all of them.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Environment  $environment
     * @param  FixProductionIssues  $fix
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Environment $environment, FixProductionIssues $fix): JsonResponse
    {
        $data = $request->validate(['key' => ['nullable', 'string', 'max:80']]);
        $fixed = $fix->handle($user, $environment, isset($data['key']) ? [(string) $data['key']] : []);

        return response()->json([
            'redirect' => route('deploy.environments.show', [$project, $environment], false),
            'message' => $fixed === [] ? __('Nothing needed fixing.') : trans_choice('Fixed :count issue. It applies with the next deploy.|Fixed :count issues. They apply with the next deploy.', count($fixed), ['count' => count($fixed)]),
        ]);
    }
}
