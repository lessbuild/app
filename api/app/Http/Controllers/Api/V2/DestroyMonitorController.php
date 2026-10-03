<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Actions\Monitoring\ArchiveMonitor;
use App\Models\User;
use App\Support\Api\ApiMonitors;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class DestroyMonitorController
{
    /**
     * Archive a monitor, keeping its history (`DELETE /api/v2/projects/{id}/monitors/{monitor}`).
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  string  $projectId
     * @param  int  $monitorId
     * @param  ArchiveMonitor  $archive
     * @return Response
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, string $projectId, int $monitorId, ArchiveMonitor $archive): Response
    {
        $monitor = ApiMonitors::monitor($request, ApiMonitors::project($request, $user, $projectId), $monitorId);
        $archive->handle($monitor, $user, $request->integer('version', $monitor->state_version));

        return response()->noContent();
    }
}
