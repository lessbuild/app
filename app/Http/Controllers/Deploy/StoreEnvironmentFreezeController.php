<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\SaveEnvironmentFreeze;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class StoreEnvironmentFreezeController
{
    /**
     * Freeze deploys to the environment for a period, read in the chosen time zone, and return to its Controls tab.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Environment  $environment
     * @param  SaveEnvironmentFreeze  $save
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Environment $environment, SaveEnvironmentFreeze $save): RedirectResponse
    {
        $data = $request->validate([
            'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date'],
            'timezone' => ['required', 'timezone:all'], 'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $zone = (string) $data['timezone'];
        $save->handle($user, $environment, CarbonImmutable::parse((string) $data['starts_at'], $zone), CarbonImmutable::parse((string) $data['ends_at'], $zone), $data['reason'] ?? null);

        return to_route('deploy.environments.show', [$project, $environment, 'tab' => 'controls'])->with('status', __('Freeze added.'));
    }
}
