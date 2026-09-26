<?php

namespace App\Modules\Deployer\Http\Livewire;

use App\Modules\Deployer\Models\Website;
use App\Modules\Deployer\Services\WebsiteProvisioningTimeline;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class WebsiteSetup extends Component
{
    public Website $model;

    /**
     * @throws \Exception
     */
    public function render(WebsiteProvisioningTimeline $timeline): View
    {
        $this->model->refresh();
        Gate::authorize('view', $this->model);

        $finished = in_array($this->model->provisioning_status, [
            Website::STATUS_ACTIVE,
            Website::STATUS_FAILED,
            WebsiteProvisioningTimeline::STATUS_CANCELED,
        ], true);

        return view('livewire.website-provisioning-timeline', [
            'deploymentTimeline' => $timeline->for($this->model),
            'provisioningFailed' => $this->model->provisioning_status === Website::STATUS_FAILED,
            'provisioningCanceled' => $this->model->provisioning_status === WebsiteProvisioningTimeline::STATUS_CANCELED,
            'poll' => ! $finished,
        ]);
    }
}
