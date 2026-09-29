<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\RequestVariableChange;
use App\Actions\Deploy\SaveEnvironmentVariable;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class StoreEnvironmentVariableController
{
    /**
     * Add or changes one variable on an environment.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Environment  $environment
     * @param  SaveEnvironmentVariable  $save
     * @param  RequestVariableChange  $requestChange
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Environment $environment, SaveEnvironmentVariable $save, RequestVariableChange $requestChange): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:255', 'regex:/\A[A-Z_][A-Z0-9_]*\z/'],
            'value' => ['present', 'nullable', 'string', 'max:65535'],
            'scope' => ['required', 'in:runtime,build,all'],
            'rotation_due_at' => ['nullable', 'date', 'after:today'],
        ]);
        $variable = ['key' => $data['key'], 'value' => (string) ($data['value'] ?? ''), 'is_secret' => $request->boolean('is_secret'), 'scope' => $data['scope'], 'rotation_due_at' => $data['rotation_due_at'] ?? null];
        if ($environment->require_variable_approval) {
            $requestChange->handle($user, $environment, 'save', $variable);

            return to_route('deploy.environments.show', [$project, $environment, 'tab' => 'variables'])->with('status', __(':key is waiting for someone else to approve it.', ['key' => $data['key']]));
        }
        $save->handle($user, $environment, $variable);

        return to_route('deploy.environments.show', [$project, $environment, 'tab' => 'variables'])->with('status', __(':key saved.', ['key' => $data['key']]));
    }
}
