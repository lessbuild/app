<?php

namespace App\Core\Services\Projects;

use App\Core\Data\Projects\ProjectEnvironmentContext;
use App\Core\Data\Projects\ProjectEnvironmentContextState;
use App\Core\Models\Project;
use App\Core\Models\ProjectEnvironment;

final class ResolveProjectEnvironmentContext
{
    public function handle(Project $project, mixed $requestedId): ProjectEnvironmentContext
    {
        if ($requestedId === null || $requestedId === '') {
            return new ProjectEnvironmentContext(ProjectEnvironmentContextState::All);
        }

        if (! is_string($requestedId)) {
            return new ProjectEnvironmentContext(ProjectEnvironmentContextState::Unavailable);
        }

        $environment = ProjectEnvironment::query()
            ->where('project_id', $project->getKey())
            ->where('status', 'active')
            ->whereKey($requestedId)
            ->first();

        if ($environment === null) {
            return new ProjectEnvironmentContext(
                ProjectEnvironmentContextState::Unavailable,
                requestedId: $requestedId,
            );
        }

        return new ProjectEnvironmentContext(
            ProjectEnvironmentContextState::Selected,
            environment: $environment,
            requestedId: $requestedId,
        );
    }
}
