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
        $permission = Gate::forUser($user)->inspect('update', $delivery->workspace);
        if ($permission->denied() || $delivery->incident_id === null) {
            return $permission;
        }

        return $delivery->incident === null
            || (string) $delivery->incident->source()?->environment?->application?->workspace_id !== (string) $delivery->workspace_id
            ? Response::denyAsNotFound()
            : Gate::forUser($user)->inspect('view', $delivery->incident);
    }

    public function update(User $user, AlertDelivery $delivery): Response
    {
        return $this->view($user, $delivery);
    }
}
