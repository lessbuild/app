<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Account;
use App\Models\AlertDelivery;
use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\ManagesMonitoring;

final class AlertDeliveryPolicy
{
    use ManagesMonitoring;

    public function create(User $user, Account|Project $scope): bool
    {
        return $this->managesAccount($user, $scope);
    }

    public function update(User $user, AlertDelivery $record): bool
    {
        return $this->managesAccount($user, $record->account_id);
    }

    public function delete(User $user, AlertDelivery $record): bool
    {
        return $this->update($user, $record);
    }
}
