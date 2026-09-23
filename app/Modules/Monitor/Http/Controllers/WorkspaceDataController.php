<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Services\CurrentWorkspace;
use App\Modules\Monitor\Services\ExportWorkspaceData;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkspaceDataController extends Controller
{
    public function index(CurrentWorkspace $currentWorkspace): View
    {
        $workspace = $currentWorkspace->get();
        Gate::authorize('view', $workspace);

        return view('monitor::settings.data', ['workspace' => $workspace]);
    }

    public function export(CurrentWorkspace $currentWorkspace, ExportWorkspaceData $export): StreamedResponse
    {
        $workspace = $currentWorkspace->get();
        Gate::authorize('update', $workspace);

        return response()->streamDownload(function () use ($workspace, $export): void {
            $output = fopen('php://output', 'wb');

            if ($output === false) {
                abort(500, 'The workspace export could not be opened.');
            }

            try {
                $export->write($workspace, $output);
            } finally {
                fclose($output);
            }
        }, 'beacon-'.$workspace->slug.'-data-export-'.now('UTC')->format('Ymd-His').'.ndjson', [
            'Content-Type' => 'application/x-ndjson; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
