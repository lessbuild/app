<?php

namespace App\Modules\Monitor\Policies;

use App\Modules\Monitor\Models\AlertRule;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class AlertRulePolicy
{
    public function create(User $user, Workspace $workspace): Response
    {
        return Gate::forUser($user)->inspect('update', $workspace);
    }

    public function view(User $user, AlertRule $alertRule): Response
    {
        return $this->inspect($user, $alertRule, 'view');
    }

    public function update(User $user, AlertRule $alertRule): Response
    {
        return $alertRule->trashed() ? Response::denyAsNotFound() : $this->inspect($user, $alertRule, 'update');
    }

    public function delete(User $user, AlertRule $alertRule): Response
    {
        return $this->update($user, $alertRule);
    }

    private function inspect(User $user, AlertRule $rule, string $ability): Response
    {
        $application = $rule->environment?->application;

        return $application === null || $rule->environment->trashed() || $application->trashed()
            ? Response::denyAsNotFound()
            : Gate::forUser($user)->inspect($ability, $rule->environment);
    }
}
