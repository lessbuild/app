<?php

namespace App\Http\Livewire;

use App\Models\Build;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class BuildDeploymentStatus extends Component
{
    public Build $build;

    public function render(): View
    {
        $this->build->refresh()->loadMissing('repository');
        abort_unless((int) auth()->id() === (int) $this->build->repository->user_id, 403);

        $log = $this->build->logs()
            ->where('type', Build::DEPLOYMENT_LOG_TYPE)
            ->first();

        return view('livewire.build-deployment-status', [
            'deploymentLog' => $log,
            'shouldPoll' => in_array($this->build->status, Build::ACTIVE_STATUSES, true),
        ]);
    }
}
