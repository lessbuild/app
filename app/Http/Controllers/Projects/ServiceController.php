<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\DisableService;
use App\Domain\Projects\Actions\EnableService;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Queries\ProjectOverviewQuery;
use App\Platform\PlatformService;
use App\Platform\ServiceRegistry;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/** A service inside a project: its landing page when enabled, otherwise its enable page. */
final class ServiceController
{
    public function __construct(private readonly ServiceRegistry $services) {}

    public function show(#[CurrentUser] User $user, Project $project, string $service, ProjectOverviewQuery $query): View
    {
        $definition = $this->service($service);
        Gate::authorize('useService', [$project, $service]);

        return view('projects.service', [
            'overview' => $query->handle($project, $user),
            'service' => $definition,
            'enabled' => $project->hasService($service),
            'canManage' => $user->can('manageService', [$project, $service]),
        ]);
    }

    public function store(#[CurrentUser] User $user, Project $project, string $service, EnableService $enable): RedirectResponse
    {
        $definition = $this->service($service);
        $enable->handle($user, $project, $service);

        return to_route('projects.services.show', [$project, $service])->with('status', __(':service is on for this project.', ['service' => $definition->name()]));
    }

    public function destroy(#[CurrentUser] User $user, Project $project, string $service, DisableService $disable): RedirectResponse
    {
        $definition = $this->service($service);
        $disable->handle($user, $project, $service);

        return to_route('projects.show', $project)->with('status', __(':service is off. Its data is kept if you turn it back on.', ['service' => $definition->name()]));
    }

    private function service(string $key): PlatformService
    {
        return $this->services->find($key) ?? abort(404);
    }
}
