<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Enums\WorkspaceRole;
use App\Modules\Analytics\Models\Workspace;
use App\Modules\Analytics\Services\AnalyticsWorkspaceAccess;
use App\Modules\Analytics\Services\ExportWorkspaceData;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class WorkspaceDataController extends Controller
{
    public function index(Request $request, Workspace $workspace, AnalyticsWorkspaceAccess $access): View
    {
        $role = $this->authorizeManager($request, $workspace, $access);

        return view('analytics::workspaces.data', [
            'workspace' => $workspace,
            'workspaceRole' => $role,
        ]);
    }

    public function export(
        Request $request,
        Workspace $workspace,
        AnalyticsWorkspaceAccess $access,
        ExportWorkspaceData $export,
    ): StreamedResponse {
        $this->authorizeManager($request, $workspace, $access);

        $actor = $request->user();

        return response()->streamDownload(function () use ($workspace, $export, $actor): void {
            $output = fopen('php://output', 'wb');

            if ($output === false) {
                abort(500, 'The Analytics workspace export could not be opened.');
            }

            try {
                $export->write($workspace, $output, $actor);
            } finally {
                fclose($output);
            }
        }, 'buildpusher-analytics-'.Str::slug($workspace->slug).'-'.now('UTC')->format('Ymd-His').'.ndjson', [
            'Content-Type' => 'application/x-ndjson; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function authorizeManager(Request $request, Workspace $workspace, AnalyticsWorkspaceAccess $access): WorkspaceRole
    {
        $role = $access->roleFor($request->user(), $workspace);
        abort_unless($role?->canManageMembers() === true, 403);

        return $role;
    }
}
