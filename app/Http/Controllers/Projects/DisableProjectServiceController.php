<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\DisableService;
use App\Models\Project;
use App\Models\User;
use App\Platform\PlatformService;
use App\Platform\ServiceRegistry;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DisableProjectServiceController
{
    /**
     * Turns services off in projects.
     *
     * @param  ServiceRegistry  $services  Looks up the service in the URL.
     */
    public function __construct(private readonly ServiceRegistry $services) {}

    /**
     * Turns a service off in the project. Its data is kept for when it's turned back on.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  string  $service
     * @param  DisableService  $disable
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, string $service, DisableService $disable): RedirectResponse
    {
        $definition = $this->service($service);
        $disable->handle($user, $project, $service);

        return to_route('projects.show', $project)->with('status', __(':service is off. Its data is kept if you turn it back on.', ['service' => $definition->name()]));
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
