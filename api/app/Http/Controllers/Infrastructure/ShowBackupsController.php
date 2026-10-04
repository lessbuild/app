<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Data\Infrastructure\BackupSummary;
use App\Models\BackupDestination;
use App\Models\Project;
use App\Models\User;
use App\Models\WebsiteBackup;
use App\Queries\Infrastructure\BackupsQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Support\Infrastructure\BackupDestinationPresets;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowBackupsController
{
    /**
     * List the account's backup destinations (never their keys) and recent backups, with when the last backup,
     * restore and verified restore happened.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  BackupsQuery  $backups
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, BackupsQuery $backups): JsonResponse
    {
        $summary = $backups->summary($project->account_id);

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'accountName' => $project->account->name,
            'destinations' => BackupDestination::query()->where('account_id', $project->account_id)->withCount(['schedules', 'backups'])->orderBy('name')->get()
                ->map(fn (BackupDestination $destination): array => [
                    'id' => $destination->id,
                    'name' => $destination->name,
                    'storageProvider' => $destination->storage_provider,
                    'region' => $destination->region,
                    'endpoint' => $destination->endpoint,
                    'bucket' => $destination->bucket,
                    'pathPrefix' => $destination->path_prefix,
                    'schedules' => (int) $destination->schedules_count,
                    'backups' => (int) $destination->backups_count,
                    'lastError' => $destination->last_error,
                    'lastVerifiedAt' => $destination->last_verified_at?->toIso8601String(),
                ])->values(),
            'backups' => $backups->recent($project->account_id)->map(fn (WebsiteBackup $backup): array => [
                ...(array) BackupSummary::from($backup),
                'websiteDeleted' => $backup->website->trashed(),
            ])->values(),
            'summary' => [
                'backup' => $summary['backup']?->toIso8601String(),
                'restore' => $summary['restore']?->toIso8601String(),
                'restoreSeconds' => $summary['restore_seconds'],
                'verification' => $summary['verification']?->toIso8601String(),
            ],
            'presets' => BackupDestinationPresets::all(),
            'canManage' => $user->can('create', [BackupDestination::class, $project]),
        ]);
    }
}
