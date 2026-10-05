<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Data\Security\FindingRow;
use App\Models\Project;
use App\Models\SecurityFinding;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowSecurityFindingsController
{
    /**
     * List the project's findings, most serious first, filtered by status (open by default), source and severity,
     * fifty to a page.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview): JsonResponse
    {
        $status = in_array($request->query('status'), ['open', 'ignored', 'resolved'], true) ? (string) $request->query('status') : 'open';
        $source = array_key_exists((string) $request->query('source'), SecurityFinding::SOURCES) ? (string) $request->query('source') : null;
        $severity = array_key_exists((string) $request->query('severity'), SecurityFinding::SEVERITIES) ? (string) $request->query('severity') : null;
        $findings = SecurityFinding::query()->where('project_id', $project->id)->where('status', $status)
            ->when($source, fn ($query, string $source) => $query->where('source', $source))
            ->when($severity, fn ($query, string $severity) => $query->where('severity', $severity))
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END")
            ->orderByDesc('last_seen_at')->orderByDesc('id')
            ->paginate(50, ['*'], 'page', max(1, $request->integer('page', 1)));

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'filters' => ['status' => $status, 'source' => $source, 'severity' => $severity],
            'findings' => collect($findings->items())->map(FindingRow::from(...))->values(),
            // How many findings each status has, with the same check and severity filters, for the tabs.
            'counts' => SecurityFinding::query()->where('project_id', $project->id)
                ->when($source, fn ($query, string $source) => $query->where('source', $source))
                ->when($severity, fn ($query, string $severity) => $query->where('severity', $severity))
                ->toBase()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status')
                ->map(fn (mixed $total): int => (int) $total),
            'page' => $findings->currentPage(),
            'lastPage' => $findings->lastPage(),
            'sources' => collect(SecurityFinding::SOURCES)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => __($label)])->values(),
            'severities' => collect(SecurityFinding::SEVERITIES)->map(fn (array $severity, string $value): array => ['value' => $value, 'label' => __($severity['label'])])->values(),
            'canManage' => $user->can('manageService', [$project, 'security']),
        ]);
    }
}
