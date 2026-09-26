<?php

declare(strict_types=1);

namespace App\Domain\Projects\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Projects\Events\ServiceEnabled;
use App\Domain\Projects\Exceptions\ProjectRuleViolation;
use App\Domain\Projects\Models\EnabledService;
use App\Domain\Projects\Models\Project;
use App\Platform\ServiceRegistry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;

final class EnableService
{
    public function __construct(private readonly ServiceRegistry $services) {}

    /** Switch a service on for a project. Returns false when it already was. */
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
