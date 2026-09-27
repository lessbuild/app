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
    public function __construct(private readonly ServiceRegistry $services) {}

    public function __invoke(#[CurrentUser] User $user, Project $project, string $service, DisableService $disable): RedirectResponse
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
