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
    public function __construct(private readonly ServiceRegistry $services) {}

    public function __invoke(#[CurrentUser] User $user, Project $project, string $service, EnableService $enable): RedirectResponse
    {
        $definition = $this->service($service);
        $enable->handle($user, $project, $service);

        return to_route('projects.services.show', [$project, $service])->with('status', __(':service is on for this project.', ['service' => $definition->name()]));
    }

    private function service(string $key): PlatformService
    {
        return $this->services->find($key) ?? abort(404);
    }
}
