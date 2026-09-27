<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Models\Project;
use App\Models\User;
use App\Platform\PlatformService;
use App\Platform\ServiceRegistry;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

final class ShowProjectServiceController
{
    /**
     * Shows a service inside a project.
     *
     * @param  ServiceRegistry  $services  Looks up the service in the URL.
     */
    public function __construct(private readonly ServiceRegistry $services) {}

    /**
     * A service inside the project. Once the service is on and has its own pages, this forwards to them; otherwise it's
     * the enable page.
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, string $service, ProjectOverviewQuery $query): View|RedirectResponse
    {
        $definition = $this->service($service);

        // A service with its own pages opens them once it's on; this page is then only its enable page.
        $landing = $definition->navItems($project->id)[0]->url ?? null;
        if ($project->hasService($service) && $landing !== null && $landing !== route('projects.services.show', [$project, $service])) {
            return redirect($landing);
        }

        return view('projects.service', [
            'overview' => $query->handle($project, $user),
            'service' => $definition,
            'enabled' => $project->hasService($service),
            'canManage' => $user->can('manageService', [$project, $service]),
        ]);
    }

    /**
     * The service named in the URL; unknown keys are a 404.
     */
    private function service(string $key): PlatformService
    {
        return $this->services->find($key) ?? abort(404);
    }
}
