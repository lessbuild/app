<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\Issue;
use App\Modules\Monitor\Services\CurrentWorkspace;
use App\Modules\Monitor\Services\Telemetry\CollectionHealthSummary;
use App\Modules\Monitor\Services\Telemetry\DashboardMetrics;
use App\Modules\Monitor\Services\WorkspaceOnboardingProgress;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    public function index(Request $request, CurrentWorkspace $currentWorkspace, DashboardMetrics $dashboardMetrics, CollectionHealthSummary $collectionHealthSummary, WorkspaceOnboardingProgress $onboardingProgress): Response
    {
        $workspace = $currentWorkspace->get();
        $rangeOptions = DashboardMetrics::RANGES;
        $range = $request->query('range', '24h');

        if (! is_string($range) || ! array_key_exists($range, $rangeOptions)) {
            $range = '24h';
        }

        $metrics = $dashboardMetrics->forWorkspace($workspace, $range);

        $applications = $workspace->applications()
            ->select(['id', 'workspace_id', 'name', 'framework', 'framework_version', 'accent'])
            ->withCount('environments')
            ->withMax('environments as last_receipt_at', 'last_seen_at')
            ->withCasts(['last_receipt_at' => 'datetime'])
            ->orderBy('name')
            ->orderBy('id')
            ->limit(6)
            ->get();
        $issueQuery = Issue::forWorkspace($workspace)->where('status', 'open');
        $issueCounts = (clone $issueQuery)->toBase()
            ->selectRaw("COUNT(*) AS total, COUNT(CASE WHEN severity = 'critical' THEN 1 END) AS critical")
            ->first();
        $openIssues = $issueQuery
            ->select(['id', 'application_id', 'type', 'severity', 'title', 'last_seen_at', 'occurrences'])
            ->with('application:id,name')
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->limit(6)
            ->get();
        $latestEvents = $dashboardMetrics->events($workspace, $metrics['from'], $metrics['until'])
            ->select(['id', 'environment_id', 'type', 'severity', 'name', 'route', 'service', 'duration_ms', 'trace_id', 'occurred_at'])
            ->with(['environment:id,application_id,name', 'environment.application:id,name'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('timestamp_unix_nano')
            ->orderByDesc('id')
            ->limit(8)
            ->get();
        $canManageApplications = Gate::allows('update', $workspace);
        $onboarding = $canManageApplications ? $onboardingProgress->forWorkspace($workspace) : null;
        if ($onboarding !== null && $onboarding['completed'] === $onboarding['total']) {
            $onboarding = null;
        }

        return response()->view('monitor::dashboard', [
            ...$metrics,
            'range' => $range,
            'rangeOptions' => $rangeOptions,
            'collectionHealth' => $collectionHealthSummary->forWorkspace($workspace),
            'applications' => $applications,
            'canManageApplications' => $canManageApplications,
            'onboarding' => $onboarding,
            'openIssues' => $openIssues,
            'openIssueCount' => (int) $issueCounts->total,
            'criticalIssueCount' => (int) $issueCounts->critical,
            'latestEvents' => $latestEvents,
            'applicationCount' => $workspace->applications()->count(),
            'activeEnvironmentCount' => Environment::forWorkspace($workspace)->where('status', 'active')->count(),
        ])->header('Cache-Control', 'private, no-store');
    }
}
