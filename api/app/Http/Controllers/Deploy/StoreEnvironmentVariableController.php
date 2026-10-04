<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\RequestVariableChange;
use App\Actions\Deploy\SaveEnvironmentVariable;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use App\Support\SetupGuideReturn;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StoreEnvironmentVariableController
{
    /**
     * Add or change one variable on an environment, then go back to its variables (or to the setup guide it came from).
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Environment  $environment
     * @param  SaveEnvironmentVariable  $save
     * @param  RequestVariableChange  $requestChange
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Environment $environment, SaveEnvironmentVariable $save, RequestVariableChange $requestChange): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:255', 'regex:/\A[A-Z_][A-Z0-9_]*\z/'],
            'value' => ['present', 'nullable', 'string', 'max:65535'],
            'scope' => ['required', 'in:runtime,build,all'],
            'rotation_due_at' => ['nullable', 'date', 'after:today'],
        ]);
        $redirect = SetupGuideReturn::from($request) ?? route('deploy.environments.show', [$project, $environment, 'tab' => 'variables'], false);
        $variable = ['key' => $data['key'], 'value' => (string) ($data['value'] ?? ''), 'is_secret' => $request->boolean('is_secret'), 'scope' => $data['scope'], 'rotation_due_at' => $data['rotation_due_at'] ?? null];
        if ($environment->require_variable_approval) {
            $requestChange->handle($user, $environment, 'save', $variable);

            return response()->json(['redirect' => $redirect, 'message' => __(':key is waiting for someone else to approve it.', ['key' => $data['key']])]);
        }
        $save->handle($user, $environment, $variable);

        return response()->json(['redirect' => $redirect, 'message' => __(':key saved.', ['key' => $data['key']])]);
    }
}
