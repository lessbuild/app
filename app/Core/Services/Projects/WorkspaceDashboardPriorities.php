<?php

namespace App\Core\Services\Projects;

use App\Core\Data\Projects\ProjectProductSnapshot;
use App\Core\Data\Projects\ProjectProductSnapshotState;
use App\Core\Data\Projects\ProjectSetupStep;
use App\Core\Data\Projects\ProjectSetupStepState;
use App\Core\Data\Projects\WorkspaceDashboardPriority;
use App\Core\Models\Project;
use App\Core\Models\ProjectConnection;
use App\Core\Models\Workspace;
use App\Core\Services\Connections\ProjectConnectionDiagnostics;
use Illuminate\Support\Collection;

final class WorkspaceDashboardPriorities
{
    public function __construct(private readonly ProjectConnectionDiagnostics $connectionDiagnostics) {}

    /**
     * @param  Collection<string, ProjectProductSnapshot>  $summaries
     * @param  Collection<string, ProjectSetupStep>  $setupSteps
     * @param  Collection<int, ProjectConnection>  $failedConnections
     * @return Collection<int, WorkspaceDashboardPriority>
     */
    public function forProject(
        Workspace $workspace,
        Project $project,
        Collection $summaries,
        Collection $setupSteps,
        Collection $failedConnections,
    ): Collection {
        $projectUrl = route('core.projects.show', [$workspace, $project]);
        $priorities = collect();

        foreach ($failedConnections as $connection) {
            $diagnostic = $this->connectionDiagnostics->forConnection($connection);

            $priorities->push(new WorkspaceDashboardPriority(
                key: 'connection:'.$connection->getKey(),
                projectName: $project->name,
                badge: $diagnostic->status,
                title: __('Repair an app connection'),
                detail: $diagnostic->nextStep ?? $diagnostic->summary,
                tone: $diagnostic->tone,
                actionUrl: $projectUrl.'#connections',
                actionLabel: __('Review connection'),
                rank: $diagnostic->tone === 'danger' ? 1 : 2,
                updatedAt: $diagnostic->lastAttemptAt ?? $connection->last_error_at,
            ));
        }

        foreach ($summaries as $product => $summary) {
            if ($summary->state !== ProjectProductSnapshotState::Attention) {
                continue;
            }

            $priorities->push(new WorkspaceDashboardPriority(
                key: 'summary:'.$project->getKey().':'.$product,
                projectName: $project->name,
                badge: __('Needs attention'),
                title: $summary->title,
                detail: $summary->detail,
                tone: 'warning',
                actionUrl: $summary->url ?? $projectUrl,
                actionLabel: $summary->url ? __('Open app') : __('Open project'),
                rank: 3,
                updatedAt: $summary->updatedAt,
            ));
        }

        $nextSetupStep = $setupSteps->first(
            fn (ProjectSetupStep $step): bool => $step->state === ProjectSetupStepState::NeedsAction,
        );

        if ($nextSetupStep instanceof ProjectSetupStep) {
            $priorities->push(new WorkspaceDashboardPriority(
                key: 'setup:'.$project->getKey().':'.$nextSetupStep->id,
                projectName: $project->name,
                badge: __('Setup needed'),
                title: $nextSetupStep->title,
                detail: $nextSetupStep->detail,
                tone: 'neutral',
                actionUrl: $nextSetupStep->url ?? $projectUrl.'#setup',
                actionLabel: $nextSetupStep->actionLabel ?? __('Resume setup'),
                rank: 4,
            ));
        }

        return $priorities->sortBy('rank')->values();
    }
}
