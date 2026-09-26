<?php

declare(strict_types=1);

namespace App\Domain\Projects\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Projects\Events\ServiceDisabled;
use App\Domain\Projects\Models\Project;
use Illuminate\Support\Facades\Gate;

final class DisableService
{
    /** Switch a service off. Its data stays, so switching it back on picks up where it left off. */
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
