<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Models\Project;
use App\Models\ServerImportAssessment;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowServerImportController
{
    /**
     * Show what inspecting a server found, before the person confirms the import. Only the person who inspected it
     * sees it, and it can be confirmed once, before it expires.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  string  $assessment
     * @param  ProjectOverviewQuery  $overview
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, string $assessment, ProjectOverviewQuery $overview): JsonResponse
    {
        $record = ServerImportAssessment::query()->where('account_id', $project->account_id)->where('user_id', $user->id)->findOrFail((int) $assessment);
        $report = (array) $record->report;

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'assessment' => [
                'id' => $record->id,
                'ip' => $record->configuration['public_ip'] ?? null,
                'hostname' => $report['hostname'] ?? null,
                'osVersion' => $report['os_version'] ?? null,
                'architecture' => $report['architecture'] ?? null,
                'memoryMb' => (int) ($report['memory_mb'] ?? 0),
                'diskFreeMb' => (int) ($report['disk_free_mb'] ?? 0),
                'fingerprint' => $report['fingerprint'] ?? null,
                'services' => array_values((array) ($report['services'] ?? [])),
                'warnings' => array_values((array) ($report['warnings'] ?? [])),
                'expiresAt' => $record->expires_at->toIso8601String(),
            ],
            'usable' => $record->consumed_at === null && $record->expires_at->isFuture(),
        ]);
    }
}
