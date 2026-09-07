<?php

namespace App\Services;

use App\Data\VerifiedRepositoryWebhook;
use App\Jobs\ReportGitHubPreviewJob;
use App\Jobs\Web\AddWebsiteJob;
use App\Models\Build;
use App\Models\Environment;
use App\Models\PreviewDeployment;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Website;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PreviewDeploymentLifecycle
{
    /**
     * Bind resource limits, deployment requests and plan features for preview lifecycle actions.
     *
     * @param  PlanLimits  $limits  Checks workspace website capacity before preview creation.
     * @param  DeploymentRequest  $deployments  Captures and dispatches preview builds.
     * @param  Entitlements  $entitlements  Checks whether the workspace plan permits previews.
     */
    public function __construct(
        private readonly PlanLimits $limits,
        private readonly DeploymentRequest $deployments,
        private readonly Entitlements $entitlements,
    ) {}

    /**
     * Apply a verified pull-request event to the matching preview and queue its next lifecycle step.
     *
     * @param  Repository  $source  The repository whose configured project supplies preview settings.
     * @param  VerifiedRepositoryWebhook  $webhook  A verified repository event with preview identity, action and revision.
     * @return string The resulting preview status, or a reason the event was ignored or rejected.
     */
    public function handle(Repository $source, VerifiedRepositoryWebhook $webhook): string
    {
        if (! $webhook->isPreviewEvent()) {
            return 'event_ignored';
        }

        $baseEnvironment = Environment::query()
            ->where('website_id', $source->website_id)
            ->where('type', '!=', 'preview')
            ->whereHas('project', fn ($query) => $query->where('preview_enabled', true)->whereNotNull('preview_domain'))
            ->with('project')
            ->orderByRaw('CASE WHEN branch = ? THEN 0 ELSE 1 END', [$source->branch])
            ->orderByRaw("CASE WHEN type = 'production' THEN 0 ELSE 1 END")
            ->first();
        if (! $baseEnvironment) {
            return 'preview_ignored';
        }
        if (! $this->entitlements->allows($baseEnvironment->project->organization, 'previews')) {
            return 'preview_plan_required';
        }

        if ($webhook->previewAction === 'closed') {
            return $this->close($source, $webhook->pullRequestNumber);
        }

        if (! $webhook->revision || ! $webhook->sourceBranch) {
            return 'invalid_preview';
        }

        $existing = PreviewDeployment::query()
            ->where('source_repository_id', $source->id)
            ->where('pull_request_number', $webhook->pullRequestNumber)
            ->first();
        if (! $existing && ! $this->limits->usageForOrganization($baseEnvironment->project->organization, 'websites')['allowed']) {
            return 'preview_limit_reached';
        }

        $preview = DB::transaction(function () use ($source, $webhook, $baseEnvironment): PreviewDeployment {
            $project = Project::query()->lockForUpdate()->findOrFail($baseEnvironment->project_id);
            $preview = PreviewDeployment::query()
                ->where('source_repository_id', $source->id)
                ->where('pull_request_number', $webhook->pullRequestNumber)
                ->lockForUpdate()
                ->first();

            if (! $preview || ! $preview->website || $preview->website->trashed()) {
                $preview = $this->create($project, $baseEnvironment, $source, $webhook, $preview);
            } else {
                $preview->update([
                    'title' => $webhook->pullRequestTitle,
                    'source_branch' => $webhook->sourceBranch,
                    'revision' => $webhook->revision,
                    'status' => $preview->website->provisioning_status === Website::STATUS_ACTIVE
                        ? PreviewDeployment::STATUS_DEPLOYING
                        : PreviewDeployment::STATUS_PROVISIONING,
                    'last_activity_at' => now(),
                    'closed_at' => null,
                ]);
                $preview->repository?->update(['branch' => $webhook->sourceBranch]);
            }

            return $preview->fresh(['website', 'repository']);
        });

        if ($preview->website?->provisioning_status === Website::STATUS_QUEUED) {
            AddWebsiteJob::dispatch($preview->website);
        } elseif ($preview->website?->provisioning_status === Website::STATUS_ACTIVE) {
            $this->queueLatest($preview);
        }

        ReportGitHubPreviewJob::dispatch($preview->id);

        return $preview->status;
    }

    /**
     * Queue the latest preview revision after website provisioning completes.
     *
     * @param  Website  $website  The provisioned website used to locate its preview.
     * @return void No value; skips missing or closed previews.
     */
    public function websiteReady(Website $website): void
    {
        $preview = PreviewDeployment::query()->where('website_id', $website->id)->first();
        if ($preview && $preview->status !== PreviewDeployment::STATUS_CLOSED) {
            $this->queueLatest($preview);
        }
    }

    /**
     * Mark active previews for the website as failed after provisioning failure.
     *
     * @param  Website  $website  The website whose nonclosed preview records should be updated.
     * @return void No value; closed previews retain their status.
     */
    public function websiteFailed(Website $website): void
    {
        PreviewDeployment::query()
            ->where('website_id', $website->id)
            ->where('status', '!=', PreviewDeployment::STATUS_CLOSED)
            ->update(['status' => PreviewDeployment::STATUS_FAILED]);
    }

    /**
     * Reconcile a finished preview build with its current desired revision.
     *
     * @param  Build  $build  The finished build used to locate the preview and compare revisions.
     * @return void No value; marks readiness/failure, queues a newer revision or attempts closed-preview cleanup.
     */
    public function buildFinished(Build $build): void
    {
        $preview = PreviewDeployment::query()->where('repository_id', $build->repository_id)->first();
        if (! $preview) {
            return;
        }
        if ($preview->status === PreviewDeployment::STATUS_CLOSED) {
            $this->deleteWebsiteWhenIdle($preview);

            return;
        }
        if ($build->status === Build::STATUS_SUCCEEDED && hash_equals($preview->revision, (string) $build->revision)) {
            $preview->update(['status' => PreviewDeployment::STATUS_READY, 'last_activity_at' => now()]);
            ReportGitHubPreviewJob::dispatch($preview->id);

            return;
        }
        if (! hash_equals($preview->revision, (string) $build->revision)) {
            $this->queueLatest($preview);

            return;
        }
        $preview->update(['status' => PreviewDeployment::STATUS_FAILED]);
        ReportGitHubPreviewJob::dispatch($preview->id);
    }

    /**
     * Close a preview, enqueue its GitHub report and attempt website cleanup.
     *
     * @param  PreviewDeployment  $preview  The preview whose closed timestamp and status are recorded.
     * @return void No value; website deletion is deferred while a deployment remains active.
     */
    public function expire(PreviewDeployment $preview): void
    {
        $preview->update(['status' => PreviewDeployment::STATUS_CLOSED, 'closed_at' => now()]);
        ReportGitHubPreviewJob::dispatch($preview->id);
        $this->deleteWebsiteWhenIdle($preview);
    }

    /**
     * Create preview website, repository and environment records from the selected source.
     *
     * @param  Project  $project  The preview-enabled project and workspace owner.
     * @param  Environment  $baseEnvironment  The nonpreview environment supplying server placement.
     * @param  Repository  $source  The source repository whose website settings and hooks are copied.
     * @param  VerifiedRepositoryWebhook  $webhook  The verified pull-request details used for preview identity and revision.
     * @param  PreviewDeployment|null  $preview  An existing preview record to repoint, or null to create one.
     * @return PreviewDeployment The created or updated preview record; the caller supplies the surrounding transaction.
     */
    private function create(
        Project $project,
        Environment $baseEnvironment,
        Repository $source,
        VerifiedRepositoryWebhook $webhook,
        ?PreviewDeployment $preview,
    ): PreviewDeployment {
        $baseWebsite = $source->website;
        $label = "PR #{$webhook->pullRequestNumber}";
        $hostname = 'pr-'.$webhook->pullRequestNumber.'-'.$project->slug.'.'.$project->preview_domain;
        $website = $project->organization->websites()->create([
            'user_id' => $project->organization->owner_id,
            'server_id' => $baseEnvironment->server_id ?: $baseWebsite->server_id,
            'name' => "{$project->name} {$label}",
            'description' => "Ephemeral preview for {$label}",
            'environment' => rtrim((string) $baseWebsite->environment)."\nAPP_ENV=preview\nBUILDPUSHER_PREVIEW={$webhook->pullRequestNumber}",
            'url' => Str::lower($hostname),
            'database_password' => Str::random(32),
            'provisioning_status' => Website::STATUS_QUEUED,
            'health_check_enabled' => $baseWebsite->health_check_enabled,
            'health_monitoring_enabled' => $baseWebsite->health_monitoring_enabled,
            'health_check_interval_minutes' => $baseWebsite->health_check_interval_minutes,
            'health_failure_threshold' => $baseWebsite->health_failure_threshold,
            'health_check_path' => $baseWebsite->health_check_path,
            'release_retention' => min(3, $baseWebsite->release_retention),
        ]);
        $repository = $project->organization->repositories()->create([
            'user_id' => $project->organization->owner_id,
            'provider_id' => $source->provider_id,
            'website_id' => $website->id,
            'name' => "{$source->name} {$label}",
            'url' => $source->url,
            'branch' => $webhook->sourceBranch,
            'description' => "Ephemeral preview source for {$label}",
            'build_commands' => $source->build_commands,
            'post_deployment_commands' => $source->post_deployment_commands,
            'webhook_enabled' => false,
        ]);
        $environment = $project->environments()->create([
            'name' => $label,
            'slug' => 'pr-'.$webhook->pullRequestNumber,
            'type' => 'preview',
            'branch' => $webhook->sourceBranch,
            'server_id' => $website->server_id,
            'website_id' => $website->id,
            'is_protected' => false,
            'requires_deployment_approval' => false,
            'hibernate_after_minutes' => 60,
        ]);
        $attributes = [
            'project_id' => $project->id,
            'source_repository_id' => $source->id,
            'environment_id' => $environment->id,
            'website_id' => $website->id,
            'repository_id' => $repository->id,
            'pull_request_number' => $webhook->pullRequestNumber,
            'title' => $webhook->pullRequestTitle,
            'source_branch' => $webhook->sourceBranch,
            'revision' => $webhook->revision,
            'status' => PreviewDeployment::STATUS_PROVISIONING,
            'url' => $website->url,
            'last_activity_at' => now(),
            'closed_at' => null,
        ];
        if ($preview) {
            $preview->update($attributes);

            return $preview;
        }

        return PreviewDeployment::query()->create($attributes);
    }

    /**
     * Create and dispatch a preview build when its repository is ready and idle.
     *
     * @param  PreviewDeployment  $preview  The preview reloaded for current revision and repository readiness.
     * @return void No value; skips repositories that are unready or already deploying.
     */
    private function queueLatest(PreviewDeployment $preview): void
    {
        $preview->refresh()->loadMissing('repository.website.server');
        if (! $preview->repository?->isDeploymentReady() || $preview->repository->website->hasActiveDeployment()) {
            return;
        }
        $build = $preview->repository->builds()->create([
            'trigger_source' => Build::TRIGGER_WEBHOOK,
            'revision' => $preview->revision,
            'commit_message' => $preview->title,
            ...$this->deployments->attributes($preview->repository),
        ]);
        $preview->update(['status' => PreviewDeployment::STATUS_DEPLOYING]);
        $this->deployments->dispatch($build);
    }

    /**
     * Find and expire the preview for one source pull request.
     *
     * @param  Repository  $source  The repository used to scope the preview lookup.
     * @param  int  $number  The source pull-request number.
     * @return string The closed status, or preview_not_found if no preview exists.
     */
    private function close(Repository $source, int $number): string
    {
        $preview = PreviewDeployment::query()
            ->where('source_repository_id', $source->id)
            ->where('pull_request_number', $number)
            ->first();
        if (! $preview) {
            return 'preview_not_found';
        }
        $this->expire($preview);

        return PreviewDeployment::STATUS_CLOSED;
    }

    /**
     * Soft-delete the preview website once no active deployment remains.
     *
     * @param  PreviewDeployment  $preview  The preview whose website may be cleaned up.
     * @return void No value; missing, already deleted and busy websites are skipped.
     */
    private function deleteWebsiteWhenIdle(PreviewDeployment $preview): void
    {
        $website = $preview->website;
        if ($website && ! $website->trashed() && ! $website->hasActiveDeployment()) {
            $website->delete();
        }
    }
}
