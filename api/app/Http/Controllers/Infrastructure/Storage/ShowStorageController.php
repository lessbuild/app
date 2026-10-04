<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure\Storage;

use App\Models\Environment;
use App\Models\Project;
use App\Models\StorageBucket;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Support\Infrastructure\BackupDestinationPresets;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowStorageController
{
    /**
     * List the project's storage buckets (never their keys) and the environments each can be attached to.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview): JsonResponse
    {
        return response()->json([
            'overview' => $overview->handle($project, $user),
            'buckets' => StorageBucket::query()->where('project_id', $project->id)->with('environment')->orderBy('name')->get()->map(fn (StorageBucket $bucket): array => [
                'id' => $bucket->id,
                'name' => $bucket->name,
                'bucket' => $bucket->bucket,
                'storageProvider' => $bucket->storage_provider,
                'region' => $bucket->region,
                'endpoint' => $bucket->endpoint,
                'environmentId' => $bucket->environment_id,
                'environment' => $bucket->environment?->name,
            ])->values(),
            'environments' => $project->environments()->orderBy('name')->get(['id', 'name', 'project_id'])
                ->map(fn (Environment $environment): array => ['value' => $environment->id, 'label' => $environment->name])->values(),
            'presets' => BackupDestinationPresets::all(),
            'canManage' => $user->can('manageService', [$project, 'infrastructure']),
        ]);
    }
}
