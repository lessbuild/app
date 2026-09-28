<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

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
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Environment $environment, SaveEnvironmentVariable $save): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:255', 'regex:/\A[A-Z_][A-Z0-9_]*\z/'],
            'value' => ['present', 'nullable', 'string', 'max:65535'],
            'scope' => ['required', 'in:runtime,build,all'],
            'rotation_due_at' => ['nullable', 'date', 'after:today'],
        ]);
        $save->handle($user, $environment, ['key' => $data['key'], 'value' => (string) ($data['value'] ?? ''), 'is_secret' => $request->boolean('is_secret'), 'scope' => $data['scope'], 'rotation_due_at' => $data['rotation_due_at'] ?? null]);

        return to_route('deploy.environments.show', [$project, $environment, 'tab' => 'variables'])->with('status', __(':key saved.', ['key' => $data['key']]));
    }
}
