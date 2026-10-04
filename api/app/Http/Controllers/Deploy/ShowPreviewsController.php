<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\Preview;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Queries\Deploy\PreviewsQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/projects/{project}/deploy/previews`. */
final class ShowPreviewsController
{
    /**
     * Return the project's previews: open ones (with their secrets approved for the revision, and the secrets the
     * person may approve), recently closed ones and their cleanup, how many the plan allows, and the repositories
     * that make them.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  PreviewsQuery  $previews
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, PreviewsQuery $previews): JsonResponse
    {
        $data = $previews->handle($project, $user);

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'used' => $data['used'],
            'limit' => $data['limit'],
            'allowed' => $data['allowed'],
            'repositories' => $data['repositories']->map(fn (Repository $repository): array => ['value' => (string) $repository->id, 'label' => $repository->name])->values(),
            'open' => $data['open']->map(function (Preview $preview) use ($data, $user): array {
                $approval = $preview->secretApprovals->firstWhere('revision', $preview->revision);

                return [
                    ...$this->summary($preview),
                    'url' => $preview->url,
                    'branch' => $preview->source_branch,
                    'revision' => $preview->revision,
                    'shortRevision' => $preview->shortRevision(),
                    'expiresAt' => $preview->expiresAt()->toIso8601String(),
                    'deploysRepositoryId' => $preview->repository?->id,
                    'websiteError' => $preview->website?->provisioning_status === 'failed' ? ($preview->website->provisioning_error ?? __('The website couldn’t be set up.')) : null,
                    'approvedSecrets' => $approval === null ? null : ['count' => count($approval->variable_versions), 'approver' => $approval->approver?->name],
                    'approvable' => $data['approvable'][$preview->id] ?? [],
                    'canOperate' => $user->can('operate', $preview),
                ];
            })->values(),
            'closed' => $data['closed']->map(fn (Preview $preview): array => [
                ...$this->summary($preview),
                'closedAt' => $preview->closed_at?->toIso8601String(),
                'cleanupStatus' => $preview->cleanup_status,
                'cleanupError' => $preview->cleanup_error,
                'canOperate' => $user->can('operate', $preview),
            ])->values(),
        ]);
    }

    /**
     * Describe a preview for both lists.
     *
     * @param  Preview  $preview
     * @return array<string, mixed>
     */
    private function summary(Preview $preview): array
    {
        return [
            'id' => $preview->id,
            'repository' => $preview->sourceRepository->name,
            'label' => $preview->label(),
            'pullRequest' => $preview->pull_request_number,
            'title' => $preview->title,
            'status' => $preview->status,
        ];
    }
}
