<?php

namespace App\Http\Livewire;

use App\Models\Build;
use App\Models\Repository;
use App\Services\RepositoryDeploymentPlan;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class RepositorySetup extends Component
{
    public Repository $model;

    /**
     * @throws \Exception
     */
    public function render(RepositoryDeploymentPlan $plan): View
    {
        $this->model->refresh();
        Gate::authorize('view', $this->model);
        $latestBuild = $this->model->builds()->latest()->first();
        $this->model->setAttribute('provisioning_status', match ($latestBuild?->status) {
            Build::STATUS_SUCCEEDED => 'active',
            Build::STATUS_FAILED => 'failed',
            Build::STATUS_CANCELED => 'canceled',
            default => $latestBuild?->status,
        });
        $this->model->setAttribute('provisioning_error', $latestBuild?->failure_message);

        return view('livewire.setup', [
            'processes' => $plan->scripts(),
        ]);
    }
}
