<?php

namespace App\Http\Livewire;

use App\Models\Repository;
use App\Services\BuildDeploymentTimeline;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class RepositoryDeploymentTimeline extends Component
{
    public Repository $model;

    public function render(BuildDeploymentTimeline $timeline): View
    {
        $this->model->refresh();
        Gate::authorize('view', $this->model);

        $latestBuild = $this->model->latestBuild()
            ->with('environment')
            ->first();

        return view('livewire.repository-deployment-timeline', [
            'deploymentTimeline' => $latestBuild ? $timeline->for($latestBuild) : [],
        ]);
    }
}
