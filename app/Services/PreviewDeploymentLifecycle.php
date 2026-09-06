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
    public function __construct(
        private readonly PlanLimits $limits,
        private readonly DeploymentRequest $deployments,
        private readonly Entitlements $entitlements,
    ) {}

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

    public function websiteReady(Website $website): void
    {
        $preview = PreviewDeployment::query()->where('website_id', $website->id)->first();
        if ($preview && $preview->status !== PreviewDeployment::STATUS_CLOSED) {
            $this->queueLatest($preview);
        }
    }

    public function websiteFailed(Website $website): void
    {
        PreviewDeployment::query()
            ->where('website_id', $website->id)
            ->where('status', '!=', PreviewDeployment::STATUS_CLOSED)
            ->update(['status' => PreviewDeployment::STATUS_FAILED]);
    }

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

    public function expire(PreviewDeployment $preview): void
    {
        $preview->update(['status' => PreviewDeployment::STATUS_CLOSED, 'closed_at' => now()]);
        ReportGitHubPreviewJob::dispatch($preview->id);
        $this->deleteWebsiteWhenIdle($preview);
    }

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

    private function deleteWebsiteWhenIdle(PreviewDeployment $preview): void
    {
        $website = $preview->website;
        if ($website && ! $website->trashed() && ! $website->hasActiveDeployment()) {
            $website->delete();
        }
    }
}
