<?php

namespace App\Modules\Analytics\Services\Billing;

use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Models\WorkspaceMembership;
use App\Modules\Analytics\Models\Workspace as AnalyticsWorkspace;

final readonly class AnalyticsBillingContext
{
    public function __construct(
        public AnalyticsWorkspace $analyticsWorkspace,
        public CoreWorkspace $coreWorkspace,
        public PlatformUser $actor,
        public WorkspaceMembership $membership,
        public string $nativeUserId,
        public string $providerAccountKey,
    ) {}
}
