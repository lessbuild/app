<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Events\Projects\ServiceEnabled;
use App\Exceptions\ProjectRuleViolation;
use App\Models\EnabledService;
use App\Models\Project;
use App\Models\User;
use App\Platform\ServiceRegistry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;

final class EnableService
{
    /**
     * Create a new EnableService instance.
     *
     * Turns a service on in a project.
     *
     * @param  ServiceRegistry  $services  Rejects keys that aren't registered services.
     */
    public function __construct(private readonly ServiceRegistry $services) {}

    /**
     * Switch a service on for a project. Returns false when it already was.
     *
     * @param  User  $actor
     * @param  Project  $project
     * @param  string  $service
     * @return bool
     */
    public function handle(User $actor, Project $project, string $service): bool
    {
        if (! $this->services->has($service)) {
            throw ProjectRuleViolation::unknownService($service);
        }
        Gate::forUser($actor)->authorize('manageService', [$project, $service]);

        if ($project->hasService($service)) {
            return false;
        }
        try {
            (new EnabledService)->forceFill(['project_id' => $project->id, 'service' => $service, 'enabled_by_id' => $actor->id])->save();
        } catch (UniqueConstraintViolationException) {
            return false; // A concurrent request enabled it first.
        }

        ServiceEnabled::dispatch($project, $service, $actor);

        return true;
    }
}
