<?php

namespace App\Modules\Monitor\Policies;

use App\Modules\Monitor\Models\AlertDelivery;
use App\Modules\Monitor\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class AlertDeliveryPolicy
{
    public function view(User $user, AlertDelivery $delivery): Response
    {
        return Gate::forUser($user)->inspect('update', $delivery->workspace);
    }

    public function update(User $user, AlertDelivery $delivery): Response
    {
        return $this->view($user, $delivery);
    }
}
