<?php

namespace App\Core\Contracts;

use App\Core\Data\Billing\ProductUsageSummary;
use App\Core\Models\Workspace;

interface WorkspaceProductUsageProvider
{
    public function summarize(Workspace $workspace): ?ProductUsageSummary;
}
