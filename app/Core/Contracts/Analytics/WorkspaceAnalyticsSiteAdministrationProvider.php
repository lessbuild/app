<?php

namespace App\Core\Contracts\Analytics;

use App\Core\Data\Analytics\AnalyticsSiteDeletionOutcome;
use App\Core\Data\Analytics\AnalyticsSiteInput;
use App\Core\Data\Analytics\AnalyticsSiteSettings;
use App\Core\Data\Analytics\AnalyticsSiteSetup;
use App\Core\Data\Analytics\AnalyticsSiteSummary;
use App\Core\Data\Analytics\WorkspaceAnalyticsSiteSnapshot;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;

interface WorkspaceAnalyticsSiteAdministrationProvider
{
    public function snapshot(PlatformUser $user, Workspace $workspace): WorkspaceAnalyticsSiteSnapshot;

    public function siteSummary(PlatformUser $user, Workspace $workspace, string $siteId): ?AnalyticsSiteSummary;

    public function setup(PlatformUser $user, Workspace $workspace, string $siteId): ?AnalyticsSiteSetup;

    public function create(PlatformUser $user, Workspace $workspace, AnalyticsSiteInput $input): AnalyticsSiteSetup;

    public function update(PlatformUser $user, Workspace $workspace, string $siteId, AnalyticsSiteSettings $settings): void;

    public function verify(PlatformUser $user, Workspace $workspace, string $siteId, string $token): bool;

    public function delete(PlatformUser $user, Workspace $workspace, string $siteId, string $confirmation): AnalyticsSiteDeletionOutcome;

    public function retryDeletion(PlatformUser $user, Workspace $workspace, string $requestId): AnalyticsSiteDeletionOutcome;

    public function deletionStatus(PlatformUser $user, Workspace $workspace, string $requestId): AnalyticsSiteDeletionOutcome;
}
