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
        $workspaceId = null;
        $found = false;
        foreach (['alert_rule_id' => 'alertRule', 'monitor_id' => 'monitor'] as $foreignKey => $relation) {
            if ($incident->{$foreignKey} === null) {
                continue;
            }
            $found = true;
            $environment = $incident->{$relation}?->environment;
            $application = $environment?->application;
            if ($environment === null || $application === null || $environment->trashed() || $application->trashed()
                || ($workspaceId !== null && $workspaceId !== $application->workspace_id)) {
                return Response::denyAsNotFound();
            }
            $workspaceId = $application->workspace_id;
            $permission = Gate::forUser($user)->inspect($ability, $environment);
            if ($permission->denied()) {
                return $permission;
            }
        }

        return $found ? Response::allow() : Response::denyAsNotFound();
    }
}
