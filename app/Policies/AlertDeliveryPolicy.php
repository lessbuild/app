<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Account;
use App\Models\AlertDelivery;
use App\Models\User;
use App\Policies\Concerns\ManagesMonitoring;

final class AlertDeliveryPolicy
{
    use ManagesMonitoring;

    /**
     * Retrying a failed or uncertain delivery: the account's settings managers.
     *
     * @param  User  $user
     * @param  AlertDelivery  $record
     * @return bool
     */
    public function update(User $user, AlertDelivery $record): bool
    {
        return $this->live($record) && $this->managesAccount($user, $record->account_id);
    }
}
