<?php

namespace App\Modules\Monitor\Policies;

use App\Modules\Monitor\Models\AlertDestination;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class AlertDestinationPolicy
{
    public function create(User $user, Workspace $workspace): Response
    {
        return Gate::forUser($user)->inspect('update', $workspace);
    }

    public function view(User $user, AlertDestination $destination): Response
    {
        return Gate::forUser($user)->inspect('update', $destination->workspace);
    }

    public function update(User $user, AlertDestination $destination): Response
    {
        return $destination->trashed() ? Response::denyAsNotFound() : $this->view($user, $destination);
    }

    public function delete(User $user, AlertDestination $destination): Response
    {
        return $this->update($user, $destination);
    }
}
