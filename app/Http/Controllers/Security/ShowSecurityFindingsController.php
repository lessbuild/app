<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Models\Project;
use App\Models\SecurityFinding;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class ShowSecurityFindingsController
{
    /**
     * List the project's findings, most serious first, filtered by status (open by default), source and severity.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @return View
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview): View
    {
        $status = in_array($request->query('status'), ['open', 'ignored', 'resolved'], true) ? (string) $request->query('status') : 'open';
        $source = array_key_exists((string) $request->query('source'), SecurityFinding::SOURCES) ? (string) $request->query('source') : null;
        $severity = array_key_exists((string) $request->query('severity'), SecurityFinding::SEVERITIES) ? (string) $request->query('severity') : null;

        return view('security.findings', [
            'overview' => $overview->handle($project, $user),
            'filters' => ['status' => $status, 'source' => $source, 'severity' => $severity],
            'findings' => SecurityFinding::query()->where('project_id', $project->id)->where('status', $status)
                ->when($source, fn ($query, string $source) => $query->where('source', $source))
                ->when($severity, fn ($query, string $severity) => $query->where('severity', $severity))
                ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END")
                ->orderByDesc('last_seen_at')->paginate(50)->withQueryString(),
            'canManage' => $user->can('manageService', [$project, 'security']),
        ]);
    }
}
