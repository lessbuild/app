<?php

namespace App\Modules\Deployer\Actions\Repository;

use App\Modules\Deployer\Data\DeploymentObservationClaim;
use App\Modules\Deployer\Data\WebsiteHealthProbeResult;
use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\DeploymentObservation;
use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\Website;
use App\Modules\Deployer\Services\WebsiteHealthProbe;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class RunDeploymentObservationAction
{
    public const CHECK_INTERVAL_MINUTES = 1;

    public const LEASE_MINUTES = 2;

    /**
     * Bind remote probe execution to the observation state machine.
     *
     * @param  WebsiteHealthProbe  $probe  Executes one bounded HTTP probe without persisting website health state.
     */
    public function __construct(private readonly WebsiteHealthProbe $probe) {}

    /**
     * Claim and execute one due observation, then persist only a still-current result.
     *
     * The probe runs outside database transactions. Queue failures clear their
     * claim for a bounded retry, while the lease remains the recovery boundary
     * for a worker that disappears before it can record a result.
     *
     * @param  int  $observationId  Observation row to execute.
     * @param  int  $queueAttempt  Current queue delivery attempt, starting at one.
     * @param  int  $maxQueueAttempts  Maximum queue attempts before an unexpected exception is terminal.
     */
    public function handle(int $observationId, int $queueAttempt = 1, int $maxQueueAttempts = 3): void
    {
        $claim = $this->claim($observationId);
        if (! $claim) {
            return;
        }

        try {
            $result = $this->probe->probe($claim->target);
            $this->recordResult($claim, $result);
        } catch (Throwable $exception) {
            $this->recordUnexpectedFailure($claim, $exception, $queueAttempt >= $maxQueueAttempts);

            throw $exception;
        }
    }

    /**
     * Claim one due observation and prepare a website-shaped immutable target.
     *
     * @return DeploymentObservationClaim|null The lease and target, or null when work is not currently valid.
     */
    private function claim(int $observationId): ?DeploymentObservationClaim
    {
        return DB::connection('deployer')->transaction(function () use ($observationId): ?DeploymentObservationClaim {
            $observation = DeploymentObservation::query()->lockForUpdate()->find($observationId);
            if (! $observation || ! in_array($observation->status, DeploymentObservation::ACTIVE_STATUSES, true)) {
                return null;
            }

            $now = now();
            if (! $observation->deadline_at || $now->greaterThan($observation->deadline_at)) {
                $this->markExpired($observation, $now);

                return null;
            }
            if ($observation->status === DeploymentObservation::STATUS_OBSERVING
                && $observation->lease_expires_at?->isFuture()) {
                return null;
            }
            if ($observation->status === DeploymentObservation::STATUS_PENDING
                && $observation->next_check_at?->isFuture()) {
                return null;
            }
            if ($observation->status === DeploymentObservation::STATUS_OBSERVING
                && ! $observation->lease_expires_at
                && $observation->next_check_at?->isFuture()) {
                return null;
            }

            $build = Build::query()->with('repository')->find($observation->build_id);
            $website = Website::query()->with('server')->find($observation->website_id);
            if (! $build || ! $website || ! $this->isCurrentDeployment($observation, $build, $website)) {
                $this->markSuperseded($observation, $now);

                return null;
            }

            if ($website->provisioning_status !== Website::STATUS_ACTIVE
                || $website->server?->provisioning_status !== Server::STATUS_ACTIVE) {
                $this->markUnavailable($observation, $now);

                return null;
            }

            $token = (string) Str::uuid();
            $observation->update([
                'status' => DeploymentObservation::STATUS_OBSERVING,
                'attempts' => min(65535, $observation->attempts + 1),
                'claim_token' => $token,
                'lease_expires_at' => $now->copy()->addMinutes(self::LEASE_MINUTES),
                'next_check_at' => null,
                'started_at' => $observation->started_at ?? $now,
                'last_error' => null,
            ]);

            $target = new Website([
                'server_id' => $observation->server_id,
                'url' => $observation->website_url,
                'health_check_path' => $observation->health_check_path,
            ]);
            $target->setRelation('server', $website->server);

            return new DeploymentObservationClaim($observation->id, $token, $target);
        }, 5);
    }

    /**
     * Record an expected HTTP result only while this worker still owns the observation lease.
     */
    private function recordResult(DeploymentObservationClaim $claim, WebsiteHealthProbeResult $result): void
    {
        DB::connection('deployer')->transaction(function () use ($claim, $result): void {
            $observation = DeploymentObservation::query()->lockForUpdate()->find($claim->observationId);
            if (! $observation || ! $this->ownsClaim($observation, $claim)) {
                return;
            }

            $now = now();
            if (! $this->currentIdentityMatches($observation)) {
                $this->markSuperseded($observation, $now);

                return;
            }

            $attributes = [
                'last_http_status' => $result->httpStatus,
                'last_duration_ms' => $result->durationMs,
                'last_checked_at' => $now,
                'claim_token' => null,
                'lease_expires_at' => null,
                'updated_at' => $now,
            ];

            if (! $result->successful) {
                $observation->update([
                    ...$attributes,
                    'status' => DeploymentObservation::STATUS_FAILED,
                    'last_error' => $this->boundedError($result->error ?: 'The website did not return a successful response.'),
                    'next_check_at' => null,
                    'completed_at' => $now,
                ]);

                return;
            }

            $successfulChecks = min(65535, $observation->successful_checks + 1);
            if ($now->greaterThanOrEqualTo($observation->deadline_at)) {
                $observation->update([
                    ...$attributes,
                    'status' => DeploymentObservation::STATUS_HEALTHY,
                    'successful_checks' => $successfulChecks,
                    'last_error' => null,
                    'next_check_at' => null,
                    'completed_at' => $now,
                ]);

                return;
            }

            $nextCheckAt = $now->copy()->addMinutes(self::CHECK_INTERVAL_MINUTES);
            if ($nextCheckAt->greaterThan($observation->deadline_at)) {
                $nextCheckAt = $observation->deadline_at->copy();
            }
            $observation->update([
                ...$attributes,
                'status' => DeploymentObservation::STATUS_OBSERVING,
                'successful_checks' => $successfulChecks,
                'last_error' => null,
                'next_check_at' => $nextCheckAt,
                'completed_at' => null,
            ]);
        }, 5);
    }

    /**
     * Release an unexpected queue failure for retry, or persist a terminal bounded failure.
     */
    private function recordUnexpectedFailure(
        DeploymentObservationClaim $claim,
        Throwable $exception,
        bool $terminal,
    ): void {
        DB::connection('deployer')->transaction(function () use ($claim, $exception, $terminal): void {
            $observation = DeploymentObservation::query()->lockForUpdate()->find($claim->observationId);
            if (! $observation || ! $this->ownsClaim($observation, $claim)) {
                return;
            }

            $now = now();
            $observation->update([
                'status' => $terminal ? DeploymentObservation::STATUS_FAILED : DeploymentObservation::STATUS_OBSERVING,
                'last_error' => $this->boundedError($exception->getMessage() ?: 'The observation worker failed.'),
                'claim_token' => null,
                'lease_expires_at' => null,
                'next_check_at' => $terminal ? null : $now,
                'completed_at' => $terminal ? $now : null,
                'updated_at' => $now,
            ]);
        }, 5);
    }

    /**
     * Confirm that the build and website still describe the exact successful target.
     */
    private function isCurrentDeployment(DeploymentObservation $observation, Build $build, Website $website): bool
    {
        return $build->status === Build::STATUS_SUCCEEDED
            && is_string($build->revision)
            && hash_equals($build->revision, $observation->revision)
            && (int) $build->repository?->website_id === (int) $observation->website_id
            && $this->websiteIdentityMatches($observation, $website)
            && (int) Build::query()
                ->where('repository_id', $build->repository_id)
                ->where('status', Build::STATUS_SUCCEEDED)
                ->max('id') === (int) $build->id;
    }

    /**
     * Revalidate identity after the remote call without requiring a website row lock.
     */
    private function currentIdentityMatches(DeploymentObservation $observation): bool
    {
        $build = Build::query()->with('repository')->find($observation->build_id);
        $website = Website::query()->find($observation->website_id);

        return $build !== null
            && $website !== null
            && $this->isCurrentDeployment($observation, $build, $website);
    }

    private function websiteIdentityMatches(DeploymentObservation $observation, Website $website): bool
    {
        return (int) $website->server_id === (int) $observation->server_id
            && $website->url === $observation->website_url
            && $website->health_check_path === $observation->health_check_path;
    }

    private function ownsClaim(DeploymentObservation $observation, DeploymentObservationClaim $claim): bool
    {
        return $observation->status === DeploymentObservation::STATUS_OBSERVING
            && is_string($observation->claim_token)
            && hash_equals($observation->claim_token, $claim->claimToken);
    }

    private function markExpired(DeploymentObservation $observation, CarbonInterface $now): void
    {
        $observation->update([
            'status' => DeploymentObservation::STATUS_EXPIRED,
            'claim_token' => null,
            'lease_expires_at' => null,
            'next_check_at' => null,
            'last_error' => 'The observation window expired before it completed.',
            'completed_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function markSuperseded(DeploymentObservation $observation, CarbonInterface $now): void
    {
        $observation->update([
            'status' => DeploymentObservation::STATUS_SUPERSEDED,
            'claim_token' => null,
            'lease_expires_at' => null,
            'next_check_at' => null,
            'completed_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function markUnavailable(DeploymentObservation $observation, CarbonInterface $now): void
    {
        $observation->update([
            'status' => DeploymentObservation::STATUS_FAILED,
            'claim_token' => null,
            'lease_expires_at' => null,
            'next_check_at' => null,
            'last_error' => 'The deployment target is not available for observation.',
            'completed_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function boundedError(string $error): string
    {
        return str($error)->limit(500, '')->toString() ?: 'The observation did not complete.';
    }
}
