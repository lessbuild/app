<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\EnableService;
use App\Models\Project;
use App\Models\User;
use App\Platform\PlatformService;
use App\Platform\ServiceRegistry;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class EnableProjectServiceController
{
    /**
     * Turns services on in projects.
     *
     * @param  ServiceRegistry  $services  Looks up the service in the URL.
     */
    public function __construct(private readonly ServiceRegistry $services) {}

    /**
     * Turns a service on in the project and opens it.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  string  $service
     * @param  EnableService  $enable
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, string $service, EnableService $enable): RedirectResponse
    {
        $definition = $this->service($service);
        $enable->handle($user, $project, $service);

        return to_route('projects.services.show', [$project, $service])->with('status', __(':service is on for this project.', ['service' => $definition->name()]));
    }

    /**
     * The service named in the URL; unknown keys are a 404.
     *
     * @param  string  $key
     * @return PlatformService
     */
    private function service(string $key): PlatformService
    {
        return $this->services->find($key) ?? abort(404);
    }
}
