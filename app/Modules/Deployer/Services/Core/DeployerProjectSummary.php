<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Contracts\ProjectEnvironmentAwareSummaryProvider;
use App\Core\Data\Projects\ProjectProductSnapshot;
use App\Core\Data\Projects\ProjectProductSnapshotState;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project as CoreProject;
use App\Core\Models\ProjectEnvironment;
use App\Core\Models\ProjectResource;
use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\Environment;

final class DeployerProjectSummary implements ProjectEnvironmentAwareSummaryProvider
{
    public function __construct(private readonly DeployerProjectLink $projects) {}

    public function summarize(PlatformUser $user, CoreProject $project): ?ProjectProductSnapshot
    {
        $legacyProject = $this->projects->projectFor($user, $project);

        if ($legacyProject === null) {
            return null;
        }

        $url = $this->projects->urlFor($legacyProject);
        $build = Build::query()
            ->whereHas('environment', fn ($query) => $query->where('project_id', $legacyProject->getKey()))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first(['id', 'status', 'revision', 'release_name', 'finished_at', 'built_at', 'updated_at']);

        if ($build === null) {
            return new ProjectProductSnapshot(
                title: __('Latest deployment'),
                detail: __('No deployments have been recorded for this project yet.'),
                state: ProjectProductSnapshotState::Empty,
                url: $url,
            );
        }

        $failed = in_array($build->status, [Build::STATUS_FAILED, Build::STATUS_REJECTED], true);
        $version = $build->revision
            ? substr($build->revision, 0, 12)
            : ($build->release_name ?: __('Build #:id', ['id' => $build->getKey()]));

        return new ProjectProductSnapshot(
            title: __('Latest deployment'),
            detail: __(':status · :version', [
                'status' => str($build->status)->headline(),
                'version' => $version,
            ]),
            state: $failed ? ProjectProductSnapshotState::Attention : ProjectProductSnapshotState::Current,
            updatedAt: $build->finished_at ?? $build->updated_at ?? $build->built_at,
            url: $url,
        );
    }

    public function summarizeForEnvironment(
        PlatformUser $user,
        CoreProject $project,
        ProjectEnvironment $environment,
    ): ?ProjectProductSnapshot {
        $legacyProject = $this->projects->projectFor($user, $project);
        $mappings = ProjectResource::query()
            ->where('project_id', $project->getKey())
            ->where('environment_id', $environment->getKey())
            ->where('product', 'deployer')
            ->where('resource_type', 'environment')
            ->where('status', 'active')
            ->get(['resource_id']);

        if ($legacyProject === null || $mappings->isEmpty()) {
            return $this->unavailableEnvironment($environment);
        }

        $sourceEnvironments = Environment::query()
            ->whereIn('id', $mappings->pluck('resource_id'))
            ->where('project_id', $legacyProject->getKey())
            ->get(['id']);

        if ($sourceEnvironments->isEmpty()) {
            return $this->unavailableEnvironment($environment);
        }

        $build = Build::query()
            ->whereIn('environment_id', $sourceEnvironments->modelKeys())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first(['id', 'status', 'revision', 'release_name', 'finished_at', 'built_at', 'updated_at']);
        $sourceEnvironmentId = $sourceEnvironments->first()->getKey();
        $url = $this->projects->urlFor($legacyProject, 'environment-'.$sourceEnvironmentId);

        if ($build === null) {
            return new ProjectProductSnapshot(
                title: __('Latest deployment · :environment', ['environment' => $environment->name]),
                detail: __('No deployments have been recorded for this mapped environment yet.'),
                state: ProjectProductSnapshotState::Empty,
                url: $url,
            );
        }

        $failed = in_array($build->status, [Build::STATUS_FAILED, Build::STATUS_REJECTED], true);
        $version = $build->revision
            ? substr($build->revision, 0, 12)
            : ($build->release_name ?: __('Build #:id', ['id' => $build->getKey()]));

        return new ProjectProductSnapshot(
            title: __('Latest deployment · :environment', ['environment' => $environment->name]),
            detail: __(':status · :version', [
                'status' => str($build->status)->headline(),
                'version' => $version,
            ]),
            state: $failed ? ProjectProductSnapshotState::Attention : ProjectProductSnapshotState::Current,
            updatedAt: $build->finished_at ?? $build->updated_at ?? $build->built_at,
            url: $url,
        );
    }

    private function unavailableEnvironment(ProjectEnvironment $environment): ProjectProductSnapshot
    {
        return new ProjectProductSnapshot(
            title: __('Deployment activity · :environment', ['environment' => $environment->name]),
            detail: __('No authorized Deployer environment is mapped to :environment.', ['environment' => $environment->name]),
            state: ProjectProductSnapshotState::Unavailable,
        );
    }
}
