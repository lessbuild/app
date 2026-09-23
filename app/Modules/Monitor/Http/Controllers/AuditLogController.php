<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Http\Requests\SearchAuditLogsRequest;
use App\Modules\Monitor\Models\AuditLog;
use App\Modules\Monitor\Services\CurrentWorkspace;
use App\Modules\Monitor\Services\WorkspacePlanLimits;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class AuditLogController extends Controller
{
    public function index(SearchAuditLogsRequest $request, CurrentWorkspace $currentWorkspace, WorkspacePlanLimits $limits): Response
    {
        $workspace = $currentWorkspace->get();
        Gate::authorize('view', $workspace);
        $filters = $request->validated();
        $actors = $workspace->members()->orderBy('name')->get(['users.id', 'name']);
        $auditLogs = null;

        if ($limits->auditLogEnabled($workspace)) {
            $query = AuditLog::forWorkspace($workspace)->with('actor:id,name')->latest('created_at')->latest('id');
            if (isset($filters['action'])) {
                $query->where('action', $filters['action']);
            }
            if (isset($filters['actor_id'])) {
                $query->where('actor_id', $filters['actor_id']);
            }
            $auditLogs = $query->paginate(40, ['*'], 'page', (int) ($filters['page'] ?? 1))
                ->appends($request->safe()->except('page'));
        }

        return response()->view('monitor::settings.audit-log', [
            'workspace' => $workspace,
            'auditLogs' => $auditLogs,
            'actions' => AuditLog::ACTION_LABELS,
            'actors' => $actors,
            'selectedAction' => $filters['action'] ?? null,
            'selectedActor' => $filters['actor_id'] ?? null,
            'auditLogEnabled' => $limits->auditLogEnabled($workspace),
        ])->header('Cache-Control', 'private, no-store');
    }
}
