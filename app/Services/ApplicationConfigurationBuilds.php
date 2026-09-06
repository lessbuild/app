<?php

namespace App\Services;

use App\Models\Build;
use App\Models\ConfigurationOperation;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\PreviewDeployment;
use App\Models\Repository;
use App\Models\Website;
use Illuminate\Support\Facades\DB;

class ApplicationConfigurationBuilds
{
    /** Atomically reserve one build. Queue delivery is a separate recoverable step. */
    public function prepare(ConfigurationOperation $operation): ?Build
    {
        $projectId = $operation->application->review->project_id;

        return DB::transaction(function () use ($operation, $projectId) {
            $project = ApplicationConfigurationLocks::project($projectId);
            $operation = ConfigurationOperation::query()->findOrFail($operation->id);
            $review = $operation->application->review;
            $environment = Environment::query()->lockForUpdate()->find($operation->environment_id);
            $website = $environment ? Website::query()->lockForUpdate()->find($environment->website_id) : null;
            $repository = Repository::query()->lockForUpdate()->find($operation->payload['repository_id'] ?? 0);
            $operation = ConfigurationOperation::query()->lockForUpdate()->findOrFail($operation->id);
            $existingBuild = $operation->build;
            if (in_array($operation->status, ['succeeded', 'failed', 'canceled'], true)) {
                return $existingBuild;
            }
            if (! $existingBuild && $operation->started_at) {
                if (! in_array($operation->status, ['succeeded', 'failed', 'canceled'], true)) {
                    $operation->update(['status' => 'failed', 'failure_code' => 'build_missing', 'completed_at' => now(), 'available_at' => null]);
                }

                return null;
            }
            if ($existingBuild && ! in_array($existingBuild->status, [Build::STATUS_QUEUED, Build::STATUS_AWAITING_APPROVAL], true)) {
                return $existingBuild;
            }
            $requester = $review->requester;
            $reason = null;
            if (! $requester || ! $project->organization->permits($requester, 'manage')) {
                $reason = 'permission_revoked';
            } elseif ($operation->kind !== 'deploy' || ! $environment || (int) $environment->project_id !== (int) $project->id
                || ! $website || (int) $website->organization_id !== (int) $project->organization_id
                || (int) $website->server?->organization_id !== (int) $project->organization_id
                || ! $repository || (int) $repository->organization_id !== (int) $project->organization_id
                || (int) $repository->provider?->organization_id !== (int) $project->organization_id
                || (int) $repository->website_id !== (int) $website->id || ! $repository->isDeploymentReady()) {
                $reason = 'target_unavailable';
            } elseif (! hash_equals($operation->payload['repository_fingerprint'] ?? '', ApplicationConfigurationRepositoryIdentity::fingerprint($repository))) {
                $reason = 'repository_changed';
            } elseif (! $this->entitled($project->organization, $operation->payload['attributes']['environment_payload'] ?? [])) {
                $reason = 'entitlement_changed';
            } elseif (PreviewDeployment::query()->where(fn ($query) => $query->where('environment_id', $environment->id)->orWhere('website_id', $website->id))
                ->where(fn ($query) => $query->where('status', '!=', PreviewDeployment::STATUS_CLOSED)->orWhereNull('closed_at'))->exists()) {
                $reason = 'preview_active';
            } elseif ($environment->deploymentBlockReason()) {
                $reason = 'deployment_gate';
            } elseif ($website->builds()->whereIn('builds.status', Build::ACTIVE_STATUSES)
                ->when($existingBuild, fn ($query) => $query->where('builds.id', '!=', $existingBuild->id))->exists()) {
                $reason = 'deployment_active';
            }
            if ($reason) {
                $operation->update(['status' => 'blocked', 'failure_code' => $reason, 'attempts' => $operation->attempts + 1]);

                return null;
            }
            if ($existingBuild) {
                if ($environment->requires_deployment_approval && ! $existingBuild->approved_at) {
                    $existingBuild->update(['status' => Build::STATUS_AWAITING_APPROVAL]);
                }
                if ($operation->status === 'blocked') {
                    $operation->update(['status' => 'build_created', 'failure_code' => null]);
                }

                return $existingBuild;
            }
            $attributes = $operation->payload['attributes'];
            // Persist origin on the encrypted build itself: deleted review/project
            // records must never turn an authorized configuration job into an ordinary job.
            $attributes['environment_payload']['configuration_operation_id'] = $operation->id;
            // A newly enabled approval requirement must not be bypassed by an old snapshot.
            if ($environment->requires_deployment_approval) {
                $attributes['status'] = Build::STATUS_AWAITING_APPROVAL;
            }
            $build = $repository->builds()->create([...$attributes, 'trigger_source' => Build::TRIGGER_API,
                'environment_id' => $environment->id, 'requested_by' => $requester->id]);
            $operation->update(['build_id' => $build->id, 'status' => 'build_created', 'failure_code' => null,
                'attempts' => $operation->attempts + 1, 'started_at' => now()]);

            return $build;
        }, 5);
    }

    private function entitled(Organization $organization, array $payload): bool
    {
        foreach (['processes' => 'workers', 'resources' => 'resources'] as $section => $feature) {
            if (! empty($payload[$section]) && ! app(Entitlements::class)->allows($organization, $feature)) {
                return false;
            }
        }

        return app(Entitlements::class)->allows($organization, 'deployments');
    }
}
