<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Models\MaintenanceWindow;
use Carbon\CarbonImmutable;

final class MaintenanceWindowState
{
    /**
     * Find the account's maintenance window in effect at a moment, if any.
     *
     * @param  string  $accountId
     * @param  CarbonImmutable  $at
     * @return MaintenanceWindow|null
     */
    public function activeForAccountId(string $accountId, CarbonImmutable $at): ?MaintenanceWindow
    {
        return MaintenanceWindow::query()->where('account_id', $accountId)
            ->where('starts_at', '<=', $at)->where('ends_at', '>', $at)->oldest('starts_at')->first();
    }

    /**
     * Determine whether one is in effect.
     *
     * @param  string  $accountId
     * @param  CarbonImmutable  $at
     * @return bool
     */
    public function isActiveForAccountId(string $accountId, CarbonImmutable $at): bool
    {
        return $this->activeForAccountId($accountId, $at) !== null;
    }
}
