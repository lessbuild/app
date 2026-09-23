<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Contracts\ProjectProductSummaryProvider;
use App\Core\Data\Projects\ProjectProductSnapshot;
use App\Core\Data\Projects\ProjectProductSnapshotState;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project as CoreProject;
use App\Modules\Deployer\Models\Build;
use Illuminate\Support\Facades\Route;

final class DeployerProjectSummary implements ProjectProductSummaryProvider
{
    public function __construct(private readonly DeployerProjectLink $projects) {}

    public function summarize(PlatformUser $user, CoreProject $project): ?ProjectProductSnapshot
    {
        $legacyProject = $this->projects->projectFor($user, $project);

        if ($legacyProject === null) {
            return null;
        }

        $url = Route::has('projects.show') ? route('projects.show', $legacyProject->getKey()) : null;
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
}
