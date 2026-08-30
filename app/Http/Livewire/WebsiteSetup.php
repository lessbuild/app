<?php

namespace App\Http\Livewire;

use App\Models\Website;
use App\Services\WebsiteProvisioningPlan;
use Illuminate\Contracts\View\View;
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
        abort_unless((int) auth()->id() === (int) $this->model->user_id, 403);

        return view('livewire.setup', [
            'processes' => $plan->scripts(),
        ]);
    }
}
