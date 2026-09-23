<?php

namespace App\Modules\Deployer\Actions\Repository;

use App\Modules\Deployer\Data\DeploymentObservationConfiguration;
use App\Modules\Deployer\Jobs\Repository\ObserveDeploymentJob;
use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\DeploymentObservation;
use App\Modules\Deployer\Models\Repository;
use App\Modules\Deployer\Models\Website;
use Illuminate\Support\Facades\DB;

class CreateDeploymentObservationAction
{
    /**
     * Create one revision-bound pending observation after a successful build commit.
     *
     * Existing active observations for the same website and repository are
     * superseded under the same transaction. This action performs no remote
     * work; the queued execution lifecycle is deliberately a separate boundary.
     *
     * @param  Build  $build  Successful build whose encrypted payload supplies the opt-in target snapshot.
     * @return DeploymentObservation|null The idempotent observation, or null for a legacy/invalid opt-in.
     */
    public function handle(Build $build): ?DeploymentObservation
    {
        $created = false;
        $observation = DB::transaction(function () use ($build, &$created): ?DeploymentObservation {
            $lockedBuild = Build::query()->lockForUpdate()->find($build->id);
            if (! $lockedBuild || $lockedBuild->status !== Build::STATUS_SUCCEEDED) {
                return null;
            }

            $configuration = DeploymentObservationConfiguration::fromPayload($lockedBuild->environment_payload);
            if (! $configuration || preg_match('/\A[0-9a-f]{40,64}\z/D', (string) $lockedBuild->revision) !== 1) {
                return null;
            }

            $existing = DeploymentObservation::query()
                ->where('build_id', $lockedBuild->id)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return $existing;
            }

            $repository = Repository::query()->find($lockedBuild->repository_id);
            $website = Website::query()->lockForUpdate()->find($configuration->websiteId);
            if (! $repository || ! $website
                || (int) $repository->website_id !== $configuration->websiteId
                || (int) $website->server_id !== $configuration->serverId
                || $website->url !== $configuration->websiteUrl
                || $website->health_check_path !== $configuration->healthCheckPath) {
                return null;
            }

            $now = now();
            DeploymentObservation::query()
                ->where('website_id', $configuration->websiteId)
                ->whereIn('status', DeploymentObservation::ACTIVE_STATUSES)
                ->whereHas('build', fn ($query) => $query
                    ->where('repository_id', $lockedBuild->repository_id)
                    ->where('id', '<', $lockedBuild->id))
                ->update([
                    'status' => DeploymentObservation::STATUS_SUPERSEDED,
                    'claim_token' => null,
                    'lease_expires_at' => null,
                    'next_check_at' => null,
                    'completed_at' => $now,
                    'updated_at' => $now,
                ]);

            $created = true;

            return DeploymentObservation::query()->create([
                'build_id' => $lockedBuild->id,
                'website_id' => $configuration->websiteId,
                'server_id' => $configuration->serverId,
                'revision' => (string) $lockedBuild->revision,
                'website_url' => $configuration->websiteUrl,
                'health_check_path' => $configuration->healthCheckPath,
                'duration_minutes' => $configuration->durationMinutes,
                'status' => DeploymentObservation::STATUS_PENDING,
                'deadline_at' => $now->copy()->addMinutes($configuration->durationMinutes),
                'next_check_at' => $now,
            ]);
        }, 5);

        if ($created && $observation) {
            // Queue only after the record transaction commits; a synchronous queue may execute immediately here.
            ObserveDeploymentJob::dispatch($observation->id);
        }

        return $observation;
    }
}
