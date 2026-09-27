<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\Project;
use App\Models\User;
use App\Services\Deploy\Configuration\ConfigurationPlanner;
use Illuminate\Support\Facades\Gate;

final class PlanConfiguration
{
    /**
     * Previews what a configuration document would change.
     *
     * @param  ConfigurationPlanner  $planner  Compares the document with the current configuration.
     */
    public function __construct(private readonly ConfigurationPlanner $planner) {}

    /**
     * What applying a configuration document would change. Nothing is written.
     *
     * @param  array<string, mixed>  $bindings
     * @return array{version: int, project_id: string, changes: list<array<string, mixed>>, fingerprint: string, omitted_objects: string, apply_available: bool}
     */
    public function handle(User $actor, Project $project, string $document, array $bindings): array
    {
        Gate::forUser($actor)->authorize('manageDeploy', $project);

        return $this->planner->plan($project, $actor, $document, $bindings);
    }
}
