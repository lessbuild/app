<?php

namespace App\Modules\Deployer\Http\Livewire;

use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Services\ServerProvisioningPlan;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class ServerSetup extends Component
{
    public Server $model;

    /**
     * @throws \Exception
     */
    public function render(ServerProvisioningPlan $plan): View
    {
        $this->model->refresh();
        Gate::authorize('view', $this->model);

        return view('livewire.setup', [
            'processes' => $plan->steps($this->model),
        ]);
    }
}
