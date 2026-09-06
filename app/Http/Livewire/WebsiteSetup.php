<?php

namespace App\Http\Livewire;

use App\Models\Website;
use App\Services\WebsiteProvisioningPlan;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class WebsiteSetup extends Component
{
    public Website $model;

    /**
     * @throws \Exception
     */
    public function render(WebsiteProvisioningPlan $plan): View
    {
        $this->model->refresh();
        Gate::authorize('view', $this->model);

        return view('livewire.setup', [
            'processes' => $plan->scripts(),
        ]);
    }
}
