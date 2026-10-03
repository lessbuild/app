<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Events\Projects\ServiceDisabled;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class DisableService
{
    /**
     * Switch a service off. Its data stays, so switching it back on picks up where it left off.
     *
     * @param  User  $actor
     * @param  Project  $project
     * @param  string  $service
     * @return bool
     */
    public function handle(User $actor, Project $project, string $service): bool
    {
        Gate::forUser($actor)->authorize('manageService', [$project, $service]);

        $deleted = $project->enabledServices()->where('service', $service)->delete() > 0;
        if ($deleted) {
            ServiceDisabled::dispatch($project, $service, $actor);
        }

        return $deleted;
    }
}
