<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\UpdateDeploymentControls;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class UpdateDeploymentControlsController
{
    /**
     * Locks or unlocks deploys to an environment and sets the window deploys may run in.
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Environment $environment, UpdateDeploymentControls $update): RedirectResponse
    {
        $data = $request->validate([
            'lock_reason' => ['nullable', 'string', 'max:500'],
            'days' => ['nullable', 'required_if_accepted:window', 'array'],
            'days.*' => ['integer', 'between:1,7'],
            'start' => ['nullable', 'required_if_accepted:window', 'date_format:H:i'],
            'end' => ['nullable', 'required_if_accepted:window', 'date_format:H:i', 'different:start'],
            'timezone' => ['nullable', 'timezone:all'],
        ]);
        $update->handle($user, $environment, [
            'locked' => $request->boolean('locked'), 'lock_reason' => $request->filled('lock_reason') ? $request->string('lock_reason')->toString() : null, 'window' => $request->boolean('window'),
            'days' => array_values(array_map('intval', (array) ($data['days'] ?? []))), 'start' => $request->filled('start') ? $request->string('start')->toString() : null,
            'end' => $request->filled('end') ? $request->string('end')->toString() : null, 'timezone' => $request->filled('timezone') ? $request->string('timezone')->toString() : null,
        ]);

        return to_route('deploy.environments.show', [$project, $environment, 'tab' => 'controls'])->with('status', __('Deployment controls saved.'));
    }
}
