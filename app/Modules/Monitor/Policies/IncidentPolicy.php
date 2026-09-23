<?php

namespace App\Modules\Monitor\Policies;

use App\Modules\Monitor\Models\Incident;
use App\Modules\Monitor\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class IncidentPolicy
{
    public function view(User $user, Incident $incident): Response
    {
        return $this->inspect($user, $incident, 'view');
    }

    public function update(User $user, Incident $incident): Response
    {
        return $this->inspect($user, $incident, 'contribute');
    }

    private function inspect(User $user, Incident $incident, string $ability): Response
    {
        $environment = $incident->source()?->environment;
        $application = $environment?->application;

        return $environment === null || $application === null || $environment->trashed() || $application->trashed()
            ? Response::denyAsNotFound()
            : Gate::forUser($user)->inspect($ability, $application->workspace);
    }
}
