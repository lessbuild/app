<?php

declare(strict_types=1);

namespace App\Services\Deploy\Configuration;

use App\Actions\Deploy\FinishBuild;
use App\Models\Build;
use App\Models\ConfigurationApplication;
use App\Models\ConfigurationOperation;
use App\Models\Repository;
use App\Services\Deploy\BuildPayload;
use App\Services\Deploy\Deployments;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Runs a configuration's deploys (ported from Deployer): an operation becomes a build once its gates pass (the reviewer
 * may still deploy, the repository is unchanged and ready, the environment isn't locked or outside its window, nothing
 * else is deploying); otherwise it's `blocked` with a reason and `configuration:dispatch` tries again each minute.
 * Results follow the builds. Failed deploys can be retried as they were reviewed; unstarted ones can be canceled.
 */
class ConfigurationOperations
{
    public function __construct(private readonly Deployments $deployments, private readonly BuildPayload $payload, private readonly FinishBuild $finish) {}

    public function deliver(ConfigurationOperation $operation): ConfigurationOperation
    {
        return DB::transaction(function () use ($operation): ConfigurationOperation {
            $operation = ConfigurationOperation::query()->with(['application.review.requester', 'application.review.project'])->lockForUpdate()->findOrFail($operation->id);
            if (! in_array($operation->status, ['pending', 'blocked'], true)) {
                return $operation;
            }
            $requester = $operation->application->review->requester;
            $repository = Repository::query()->with(['environment', 'website.server', 'provider'])->find($operation->payload['repository_id']);
            $reason = match (true) {
                $repository === null || $repository->project_id !== $operation->application->review->project_id || $repository->environment_id !== $operation->environment_id || ! $repository->isDeploymentReady() => 'target_unavailable',
                ! $requester->can('deploy', $repository) => 'permission_revoked',
                ! hash_equals($operation->payload['repository_fingerprint'], ConfigurationIdentity::repository($repository)) => 'repository_changed',
                $this->deployments->blockReason($repository) !== null => 'deployment_gate',
                default => null,
            };
            $build = $reason === null ? $this->deployments->queue($repository, ['trigger_source' => 'api'], $requester) : null;
            if ($build === null) {
                $operation->forceFill(['status' => 'blocked', 'failure_code' => $reason ?? 'deployment_active', 'attempts' => $operation->attempts + 1])->save();

                return $operation;
            }
            $operation->forceFill([
                'status' => $build->status === Build::STATUS_AWAITING_APPROVAL ? 'awaiting_approval' : 'delivered', 'build_id' => $build->id,
                'failure_code' => null, 'attempts' => $operation->attempts + 1, 'started_at' => now(),
            ])->save();

            return $operation;
        });
    }

    /** Bring operations up to date with their builds, and the application's status with its operations. */
    public function refresh(ConfigurationApplication $application): ConfigurationApplication
    {
        $operations = $application->relatedOperations()->with('build')->withExists('retry')->orderBy('id')->get();
        foreach ($operations as $operation) {
            $build = $operation->build;
            if ($build === null && $operation->started_at !== null && ! in_array($operation->status, ConfigurationOperation::FINISHED, true)) {
                $operation->forceFill(['status' => 'failed', 'failure_code' => 'build_missing', 'completed_at' => now()])->save();
            }
            if ($build === null) {
                continue;
            }
            if ($build->isActive()) {
                $current = $build->status === Build::STATUS_AWAITING_APPROVAL ? 'awaiting_approval' : 'delivered';
                if ($operation->status !== $current) {
                    $operation->forceFill(['status' => $current])->save();
                }

                continue;
            }
            $status = match ($build->status) {
                Build::STATUS_SUCCEEDED => 'succeeded',
                Build::STATUS_CANCELED, Build::STATUS_REJECTED => 'canceled',
                default => 'failed',
            };
            if ($operation->status !== $status) {
                $operation->forceFill(['status' => $status, 'completed_at' => $build->finished_at ?? now(), 'failure_code' => $status === 'succeeded' ? null : 'deployment_'.$status])->save();
            }
        }
        $statuses = $operations->reject(fn (ConfigurationOperation $operation): bool => (bool) $operation->getAttribute('retry_exists'))->pluck('status');
        $status = match (true) {
            $operations->isEmpty() => 'locally_applied',
            $statuses->contains('failed'), $statuses->contains('canceled') => 'remote_failed',
            $statuses->every(fn (string $value): bool => $value === 'succeeded') => 'succeeded',
            $statuses->contains('blocked') => 'needs_attention',
            $statuses->contains('awaiting_approval') => 'awaiting_approval',
            $statuses->contains('delivered') => 'deploying',
            default => 'awaiting_dispatch',
        };
        if ($application->status !== $status) {
            $application->forceFill(['status' => $status])->save();
        }

        return $application;
    }

    /** Try a failed or canceled deploy again, exactly as reviewed; anything changed since needs a new review. */
    public function retry(ConfigurationOperation $original): ConfigurationOperation
    {
        $retry = DB::transaction(function () use ($original): ConfigurationOperation {
            $original = ConfigurationOperation::query()->with(['build', 'retry'])->lockForUpdate()->findOrFail($original->id);
            if ($original->retry !== null) {
                return $original->retry;
            }
            $canceledBeforeBuild = $original->build === null && $original->status === 'canceled' && $original->started_at === null;
            if (! $canceledBeforeBuild && ! in_array($original->build?->status, [Build::STATUS_FAILED, Build::STATUS_CANCELED, Build::STATUS_REJECTED], true)) {
                $this->invalid('Only a deploy that failed or was canceled can be retried.');
            }
            $latest = ConfigurationOperation::query()->where('environment_id', $original->environment_id)->where('kind', 'deploy')->latest('id')->first();
            if ($latest?->id !== $original->id) {
                $this->invalid('A newer deploy of this environment exists. Use it, or create a new review.');
            }
            $repository = Repository::query()->with(['environment', 'website'])->find($original->payload['repository_id']);
            if ($repository === null || $repository->environment_id !== $original->environment_id
                || ! hash_equals($original->intent_digest, ConfigurationIdentity::intent($original->payload['repository_fingerprint'], $this->payload->for($repository)))) {
                $this->invalid('The environment changed after this deploy. Create a new review.');
            }
            $retry = new ConfigurationOperation;
            $retry->forceFill([
                'configuration_application_id' => $original->configuration_application_id, 'environment_slug' => $original->environment_slug, 'environment_id' => $original->environment_id,
                'kind' => 'deploy', 'status' => 'pending', 'intent_digest' => $original->intent_digest, 'payload' => $original->payload,
                'retry_of_operation_id' => $original->id, 'retry_sequence' => $original->retry_sequence + 1,
            ])->save();
            ConfigurationApplication::query()->whereHas('referencedOperations', fn ($query) => $query->where('configuration_operations.id', $original->id))
                ->each(fn (ConfigurationApplication $application) => $application->referencedOperations()->syncWithoutDetaching([$retry->id]));

            return $retry;
        });
        if ($this->deliver($retry)->status === 'blocked') {
            $this->invalid('The retry is waiting on a gate (access, repository, lock, window or a running deploy); it starts when that clears.');
        }

        return $retry;
    }

    /** Cancel a deploy that hasn't started on the server (a running one is canceled from its deploy page). */
    public function cancel(ConfigurationOperation $operation): ConfigurationOperation
    {
        return DB::transaction(function () use ($operation): ConfigurationOperation {
            $operation = ConfigurationOperation::query()->with('build')->lockForUpdate()->findOrFail($operation->id);
            if ($operation->status === 'canceled') {
                return $operation;
            }
            $build = $operation->build;
            if (in_array($operation->status, ['succeeded', 'failed'], true) || ($build !== null && ! in_array($build->status, [Build::STATUS_QUEUED, Build::STATUS_AWAITING_APPROVAL], true))) {
                throw ValidationException::withMessages(['operation' => __('Only a deploy that hasn’t started on the server can be canceled here. Use the deploy page for a running one.')]);
            }
            if ($build !== null) {
                $this->finish->handle($build, Build::STATUS_CANCELED);
            }
            $operation->forceFill(['status' => 'canceled', 'failure_code' => 'operation_canceled', 'completed_at' => now()])->save();

            return $operation;
        });
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['operation' => $message]);
    }
}
