<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Data\Monitoring\DashboardForm;
use App\Data\Monitoring\MonitorSummary;
use App\Data\Monitoring\ObjectiveSummary;
use App\Models\Dashboard;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Project;
use App\Models\ServiceLevelObjective;
use App\Models\User;
use App\Queries\Monitoring\DashboardReportQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Queries\Telemetry\TelemetrySummaryQuery;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowDashboardController
{
    /**
     * Show a dashboard: each of its widgets with its data, across every project in the account.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Dashboard  $dashboard
     * @param  ProjectOverviewQuery  $overview
     * @param  DashboardReportQuery  $report
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Dashboard $dashboard, ProjectOverviewQuery $overview, DashboardReportQuery $report): JsonResponse
    {
        return response()->json([
            'overview' => $overview->handle($project, $user),
            'dashboard' => ['id' => $dashboard->id, 'name' => $dashboard->name, 'description' => $dashboard->description, 'range' => __(TelemetrySummaryQuery::RANGES[$dashboard->range] ?? 'Last 24 hours')],
            'widgets' => array_map(fn (array $widget): array => ['type' => $widget['type'], 'label' => $widget['label'], 'data' => self::data($widget['type'], $widget['data'])], $report->handle($dashboard)),
            'form' => DashboardForm::for($dashboard),
            'canManage' => $user->can('update', $dashboard),
        ]);
    }

    /**
     * Turn a widget's data into what the page shows.
     *
     * @param  string  $type
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function data(string $type, array $data): array
    {
        /** @var iterable<int, Incident> $incidents */
        $incidents = $data['incidents'] ?? [];
        /** @var iterable<int, Monitor> $monitors */
        $monitors = $data['monitors'] ?? [];
        /** @var list<array{objective: ServiceLevelObjective, report: array{compliance: float|null, budget_remaining: float|null, status: string}}> $objectives */
        $objectives = $data['objectives'] ?? [];
        /** @var iterable<int, Project> $projects */
        $projects = $data['projects'] ?? [];

        return match ($type) {
            'incidents' => ['incidents' => collect($incidents)->map(fn (Incident $incident): array => [
                'id' => $incident->id, 'projectId' => $incident->project_id, 'project' => $incident->project?->name, 'title' => $incident->title,
                'status' => $incident->status, 'statusLabel' => __($incident->statusLabel()), 'openedAt' => $incident->opened_at->toIso8601String(),
            ])->values()],
            'monitors' => ['monitors' => collect($monitors)->map(fn (Monitor $monitor): array => [
                ...(array) MonitorSummary::from($monitor), 'projectId' => $monitor->environment->project_id, 'project' => $monitor->environment->project->name,
            ])->values()],
            'objectives' => ['objectives' => array_map(fn (array $row): ObjectiveSummary => ObjectiveSummary::from($row['objective'], $row['report']), $objectives)],
            'projects' => ['projects' => collect($projects)->map(fn (Project $item): array => [
                'id' => $item->id, 'name' => $item->name, 'environments' => (int) ($item->environments_count ?? 0),
                'lastReceivedAt' => $item->getAttribute('last_received_at') === null ? null : CarbonImmutable::parse((string) $item->getAttribute('last_received_at'), 'UTC')->toIso8601String(),
            ])->values()],
            default => $data,
        };
    }
}
