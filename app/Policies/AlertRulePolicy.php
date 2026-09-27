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

    public function create(User $user, Project $project): bool
    {
        return $this->managesMonitoring($user, $project) && $user->hasVerifiedEmail();
    }

    public function update(User $user, AlertRule $record): bool
    {
        return $this->live($record) && $this->managesMonitoring($user, $record->environment->project) && $user->hasVerifiedEmail();
    }

    public function delete(User $user, AlertRule $record): bool
    {
        return $this->update($user, $record);
    }
}
