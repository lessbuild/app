<?php

namespace App\Http\Livewire;

use App\Models\Server;
use App\Services\ServerProvisioningPlan;
use Illuminate\Contracts\View\View;
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
        abort_unless((int) auth()->id() === (int) $this->model->user_id, 403);

        return view('livewire.setup', [
            'processes' => $plan->steps($this->model),
        ]);
    }
}
