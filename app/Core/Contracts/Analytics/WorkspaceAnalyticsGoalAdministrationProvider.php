<?php

namespace App\Core\Contracts\Analytics;

use App\Core\Data\Analytics\AnalyticsGoalInput;
use App\Core\Data\Analytics\AnalyticsGoalSnapshot;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;

interface WorkspaceAnalyticsGoalAdministrationProvider
{
    public function snapshot(PlatformUser $user, Workspace $workspace, string $siteId): ?AnalyticsGoalSnapshot;

    public function create(PlatformUser $user, Workspace $workspace, string $siteId, AnalyticsGoalInput $input): void;

    public function update(PlatformUser $user, Workspace $workspace, string $siteId, string $goalId, AnalyticsGoalInput $input): void;

    public function delete(PlatformUser $user, Workspace $workspace, string $siteId, string $goalId): void;
}
