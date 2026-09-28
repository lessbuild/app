<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AlertRule;
use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\ManagesMonitoring;

final class AlertRulePolicy
{
    use ManagesMonitoring;

    /**
     * Creating an alert rule: people who manage Monitoring in the project, with a verified email since rules send
     * alerts.
     *
     * @param  User  $user
     * @param  Project  $project
     * @return bool
     */
    public function create(User $user, Project $project): bool
    {
        return $this->managesMonitoring($user, $project) && $user->hasVerifiedEmail();
    }

    /**
     * Changing a rule: the same people as create, while it isn't archived.
     *
     * @param  User  $user
     * @param  AlertRule  $record
     * @return bool
     */
    public function update(User $user, AlertRule $record): bool
    {
        return $this->live($record) && $this->managesMonitoring($user, $record->environment->project) && $user->hasVerifiedEmail();
    }

    /**
     * Archiving a rule, allowed to the same people as update.
     *
     * @param  User  $user
     * @param  AlertRule  $record
     * @return bool
     */
    public function delete(User $user, AlertRule $record): bool
    {
        return $this->update($user, $record);
    }
}
