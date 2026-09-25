<?php

namespace App\Core\Contracts\Analytics;

use App\Core\Data\Analytics\AnalyticsDataSnapshot;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use Symfony\Component\HttpFoundation\StreamedResponse;

interface WorkspaceAnalyticsDataAdministrationProvider
{
    public function snapshot(PlatformUser $user, Workspace $workspace, ?string $siteId = null): AnalyticsDataSnapshot;

    /** @param array{days: int, path: ?string, source: ?string, campaign: ?string, device: ?string} $filters */
    public function requestReport(PlatformUser $user, Workspace $workspace, string $siteId, array $filters): void;

    public function retryReport(PlatformUser $user, Workspace $workspace, string $siteId, string $exportId): void;

    public function downloadReport(PlatformUser $user, Workspace $workspace, string $siteId, string $exportId): StreamedResponse;

    /** Return a private streamed NDJSON download after rechecking native manager access. */
    public function workspaceExport(PlatformUser $user, Workspace $workspace): StreamedResponse;
}
