<?php

namespace App\Http\Livewire;

use App\Models\Build;
use App\Services\BuildDeploymentTimeline;
use App\Services\DeploymentFailureGuidance;
use App\Services\RepositoryDeploymentPlan;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class BuildDeploymentStatus extends Component
{
    public Build $build;

    /**
     * Refresh and authorize the bound build, then render deployment progress, logs, and recovery guidance.
     *
     * The view polls active builds and exposes rollback candidates only after deployment failure.
     */
    public function render(
        RepositoryDeploymentPlan $plan,
        DeploymentFailureGuidance $guidance,
        BuildDeploymentTimeline $timeline,
    ): View {
        $this->build->refresh()->loadMissing([
            'repository.website.server',
            'environment.project',
            'promotedFrom.environment',
            'promotions.environment',
            'requester',
            'approver',
            'rejecter',
            'configurationOperation.application.review',
        ]);
        Gate::authorize('view', $this->build);

        $log = $this->build->logs()
            ->where('type', Build::DEPLOYMENT_LOG_TYPE)
            ->first();

        return view('livewire.build-deployment-status', [
            'deploymentLog' => $log,
            'previousBuild' => $this->build->previousInRepository(),
            'nextBuild' => $this->build->nextInRepository(),
            'shouldPoll' => $this->build->statusEnum()?->isActive() === true,
            'processes' => $plan->scripts(),
            'failureGuidance' => $this->build->status === Build::STATUS_FAILED
                ? $guidance->for($this->build, $plan)
                : null,
            'rollbackCandidate' => $this->build->status === Build::STATUS_FAILED
                ? $this->build->latestRestorableBefore()
                : null,
            'deploymentTimeline' => $timeline->for($this->build),
            'website' => $this->build->repository->website,
        ]);
    }
}
