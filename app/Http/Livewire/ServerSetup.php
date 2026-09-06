<?php

namespace App\Http\Livewire;

use App\Models\Server;
use App\Services\ServerProvisioningPlan;
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
