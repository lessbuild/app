<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\RequestVariableChange;
use App\Actions\Deploy\SaveEnvironmentVariable;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class UpdateEnvironmentVariableController
{
    /**
     * Change one variable's value, scope, whether it's secret, or its rotation date, as a new version. A secret left
     * empty keeps its value; it can only stop being secret with a new value, so editing never reveals one.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Environment  $environment
     * @param  EnvironmentVariable  $variable
     * @param  SaveEnvironmentVariable  $save
     * @param  RequestVariableChange  $requestChange  For environments where someone else approves variable changes.
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Environment $environment, EnvironmentVariable $variable, SaveEnvironmentVariable $save, RequestVariableChange $requestChange): JsonResponse
    {
        $data = $request->validate([
            'value' => ['nullable', 'string', 'max:65535'],
            'scope' => ['required', 'in:runtime,build,all'],
            'rotation_due_at' => ['nullable', 'date', 'after:today'],
        ]);
        $secret = $request->boolean('is_secret');
        $value = (string) ($data['value'] ?? '');
        if ($variable->is_secret && $value === '') {
            if (! $secret) {
                throw ValidationException::withMessages(['value' => __('Enter the value to stop hiding it; a secret’s value is never shown.')]);
            }
            $value = (string) $variable->value;
        }
        $changes = ['key' => $variable->key, 'value' => $value, 'is_secret' => $secret, 'scope' => $data['scope'], 'rotation_due_at' => $data['rotation_due_at'] ?? null];
        $redirect = route('deploy.environments.show', [$project, $environment, 'tab' => 'variables'], false);
        if ($environment->require_variable_approval) {
            $requestChange->handle($user, $environment, 'save', $changes);

            return response()->json(['redirect' => $redirect, 'message' => __(':key is waiting for someone else to approve it.', ['key' => $variable->key])]);
        }
        $save->handle($user, $environment, $changes);

        return response()->json(['redirect' => $redirect, 'message' => __(':key saved.', ['key' => $variable->key])]);
    }
}
