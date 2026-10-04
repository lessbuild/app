<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\ReplaceEnvironmentVariables;
use App\Actions\Deploy\RequestVariableChange;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReplaceEnvironmentVariablesController
{
    /**
     * Replace an environment's variables with the pasted `.env` text.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Environment  $environment
     * @param  ReplaceEnvironmentVariables  $replace
     * @param  RequestVariableChange  $requestChange
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Environment $environment, ReplaceEnvironmentVariables $replace, RequestVariableChange $requestChange): JsonResponse
    {
        $request->validate(['variables' => ['present', 'nullable', 'string', 'max:200000']]);
        if ($environment->require_variable_approval) {
            $requestChange->handle($user, $environment, 'replace', ['contents' => (string) $request->input('variables', '')]);

            return response()->json(['redirect' => route('deploy.environments.show', [$project, $environment, 'tab' => 'variables'], false), 'message' => __('The new variables are waiting for someone else to approve them.')]);
        }
        $count = $replace->handle($user, $environment, (string) $request->input('variables', ''));

        return response()->json(['redirect' => route('deploy.environments.show', [$project, $environment, 'tab' => 'variables'], false), 'message' => trans_choice(':count variable set.|:count variables set.', $count)]);
    }
}
