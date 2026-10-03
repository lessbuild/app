<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\DisableService;
use App\Models\Project;
use App\Models\User;
use App\Platform\PlatformService;
use App\Platform\ServiceRegistry;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class DisableProjectServiceController
{
    /**
     * Create a new DisableProjectServiceController instance.
     *
     * Turns services off in projects.
     *
     * @param  ServiceRegistry  $services  Looks up the service in the URL.
     */
    public function __construct(private readonly ServiceRegistry $services) {}

    /**
     * Turn a service off in the project. Its data is kept for when it's turned back on.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  string  $service
     * @param  DisableService  $disable
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, string $service, DisableService $disable): JsonResponse
    {
        $definition = $this->service($service);
        $disable->handle($user, $project, $service);

        return response()->json(['redirect' => route('projects.show', $project, false), 'message' => __(':service is off. Its data is kept if you turn it back on.', ['service' => $definition->name()])]);
    }

    /**
     * Find the service named in the URL; unknown keys are a 404.
     *
     * @param  string  $key
     * @return PlatformService
     */
    private function service(string $key): PlatformService
    {
        return $this->services->find($key) ?? abort(404);
    }
}
