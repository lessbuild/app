<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Data\Deploy\RepositoryFormOptions;
use App\Models\Build;
use App\Models\Preview;
use App\Models\Project;
use App\Models\Repository;
use App\Models\RepositoryWebhookDelivery;
use App\Models\ScheduledDeploy;
use App\Models\User;
use App\Models\Website;
use App\Queries\Deploy\RepositoryFormQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Infrastructure\DatabaseCommands;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/projects/{project}/deploy/repositories/{repository}`. */
final class ShowRepositoryController
{
    /**
     * Return a repository: where it deploys to and whether it's ready, its deploys and booked deploys, push deploys
     * with recent deliveries, its settings and preview settings, and what the person may do.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Repository  $repository
     * @param  ProjectOverviewQuery  $overview
     * @param  RepositoryFormQuery  $form
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Repository $repository, ProjectOverviewQuery $overview, RepositoryFormQuery $form): JsonResponse
    {
        $repository->load(['website.server', 'environment', 'provider', 'preview']);
        $website = $repository->website;
        $siblings = Website::query()->where('server_id', $website->server_id)->whereKeyNot($website->id)
            ->whereNotIn('id', Preview::query()->whereNotNull('website_id')->select('website_id'))->orderBy('name')->get(['id', 'name']);

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'repository' => [
                'id' => $repository->id,
                'name' => $repository->name,
                'url' => $repository->url,
                'branch' => $repository->branch,
                'providerId' => $repository->provider_id,
                'host' => $repository->provider?->repositoryHost(),
                'websiteId' => $website->id,
                'website' => $website->name,
                'server' => $website->server?->label(),
                'environmentId' => $repository->environment_id,
                'environment' => $repository->environment?->name,
                'timezone' => $repository->environment->deployment_window_timezone ?? 'UTC',
                'ready' => $repository->isDeploymentReady(),
                'isPreview' => $repository->preview !== null,
                'deploymentRoot' => $repository->deployment_root,
                'buildCommands' => $repository->build_commands,
                'postDeploymentCommands' => $repository->post_deployment_commands,
                'autoDeployIncludePaths' => $repository->auto_deploy_include_paths ?? [],
                'autoDeployExcludePaths' => $repository->auto_deploy_exclude_paths ?? [],
                'webhookEnabled' => $repository->webhook_enabled,
                'webhookUrl' => route('webhooks.repositories.receive', $repository->id),
                'webhookLastReceivedAt' => $repository->webhook_last_received_at?->toIso8601String(),
                'buildCacheEnabled' => $repository->build_cache_enabled,
                'previewsEnabled' => $repository->previews_enabled,
                'previewDomain' => $repository->preview_domain,
                'previewTtlHours' => $repository->preview_ttl_hours,
                'previewInitializationCommand' => $repository->preview_initialization_command,
                'previewDatabaseSourceWebsiteId' => $repository->preview_database_source_website_id,
                'previewDatabaseMode' => $repository->preview_database_mode,
                'previewDatabaseAnonymise' => $repository->preview_database_anonymise,
            ],
            'builds' => $repository->builds()->with('requester')->latest('id')->limit(30)->get()->map(fn (Build $build): array => [
                'id' => $build->id,
                'status' => $build->status,
                'revision' => $build->shortRevision(),
                'commitMessage' => $build->commit_message,
                'trigger' => $build->trigger_source,
                'requester' => $build->requester?->name,
                'createdAt' => $build->created_at?->toIso8601String(),
            ])->values(),
            'deliveries' => $repository->webhookDeliveries()->latest('id')->limit(10)->get()->map(fn (RepositoryWebhookDelivery $delivery): array => [
                'id' => $delivery->id,
                'revision' => $delivery->revision === null ? null : substr($delivery->revision, 0, 12),
                'status' => $delivery->status,
                'createdAt' => $delivery->created_at?->toIso8601String(),
            ])->values(),
            'scheduledDeploys' => ScheduledDeploy::query()->where('repository_id', $repository->id)->where('status', ScheduledDeploy::STATUS_PENDING)->with('creator')->orderBy('run_at')->get()
                ->map(fn (ScheduledDeploy $booked): array => ['id' => $booked->id, 'runAt' => $booked->run_at->toIso8601String(), 'ref' => $booked->git_ref, 'creator' => $booked->creator?->name])->values(),
            'previewSources' => $siblings->map(fn (Website $sibling): array => ['value' => (string) $sibling->id, 'label' => $sibling->name])->values(),
            'sampleRows' => DatabaseCommands::SAMPLE_ROWS,
            'projectSlug' => $project->slug,
            'options' => RepositoryFormOptions::from($form->handle($project)),
            'canDeploy' => $user->can('deploy', $repository),
            'canManage' => $user->can('update', $repository),
        ]);
    }
}
