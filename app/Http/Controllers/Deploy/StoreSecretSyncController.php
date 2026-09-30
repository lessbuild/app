<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\SaveSecretSync;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class StoreSecretSyncController
{
    /**
     * Connect a password manager to the environment and return to its variables.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Environment  $environment
     * @param  SaveSecretSync  $save
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Environment $environment, SaveSecretSync $save): RedirectResponse
    {
        abort_unless($environment->project_id === $project->id, 404);
        $data = $request->validate([
            'provider' => ['required', 'string', 'in:doppler,onepassword,aws'], 'name' => ['nullable', 'string', 'max:80'],
            'token' => ['nullable', 'string', 'max:500'], 'host' => ['nullable', 'string', 'max:255'], 'vault' => ['nullable', 'string', 'max:100'], 'item' => ['nullable', 'string', 'max:100'],
            'region' => ['nullable', 'string', 'max:30'], 'access_key' => ['nullable', 'string', 'max:128'], 'secret_key' => ['nullable', 'string', 'max:256'], 'secret_id' => ['nullable', 'string', 'max:512'],
        ]);
        $save->handle($user, $environment, $data['provider'], (string) ($data['name'] ?? ''), $data);

        return to_route('deploy.environments.show', [$project, $environment, 'tab' => 'variables'])->withFragment('secret-syncs')->with('status', __('Connected. Its secrets are syncing now.'));
    }
}
