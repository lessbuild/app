<?php

namespace App\Core\Contracts;

use App\Core\Data\Costs\WorkspaceCostBreakdown;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;

/** Supplies an authorized cost summary while retaining product database ownership. */
interface WorkspaceCostBreakdownProvider
{
    public function summarize(PlatformUser $user, Workspace $workspace): ?WorkspaceCostBreakdown;

    public function updateMonthlyBudget(PlatformUser $user, Workspace $workspace, ?float $amount): bool;
}
