<?php

namespace App\Actions\Web;

use App\Jobs\Web\CleanupWebsitePlacementJob;
use App\Models\Website;
use App\Services\PreviewDeploymentLifecycle;
use App\Services\ProvisioningCallbackValidator;
use App\Services\WebsiteProvisioningCallbackGuard;
use App\Services\WebsiteProvisioningPlan;
use Illuminate\Support\Facades\DB;

class RecordWebsiteProvisioningStatusAction
{
    public function __construct(
        private readonly PreviewDeploymentLifecycle $previews,
        private readonly ProvisioningCallbackValidator $validator,
        private readonly WebsiteProvisioningCallbackGuard $guard,
        private readonly WebsiteProvisioningPlan $plan,
    ) {}

    /**
     * Record a current website provisioning stage and reconcile its placement.
     *
     * Preview bookkeeping intentionally remains inside the transaction and
     * placement cleanup remains after-commit, matching the callback contract.
     *
     * @param  Website  $website  Website receiving the signed status callback.
     * @param  mixed  $attempt  Raw attempt token checked against the locked row.
     * @param  mixed  $status  Raw status validated after lifecycle acceptance.
     * @return bool Whether the callback was accepted for the current attempt.
     */
    public function handle(Website $website, mixed $attempt, mixed $status): bool
    {
        return DB::transaction(function () use ($website, $attempt, $status): bool {
            $locked = Website::query()->lockForUpdate()->findOrFail($website->id);
            if (! $this->guard->acceptsLifecycle($locked, $attempt)) {
                return false;
            }

            $finalStage = $this->plan->finalStage();
            $status = $this->validator->status($status, $finalStage);
            if ($status > $locked->setup_stage) {
                $locked->update(['setup_stage' => $status]);
            }

            if ($status === $finalStage) {
                $previousServerId = $locked->previous_server_id;
                $locked->update([
                    'provisioning_status' => Website::STATUS_ACTIVE,
                    'provisioned_at' => now(),
                    'provisioning_error' => null,
                ]);
                $this->previews->websiteReady($locked->fresh());

                if ($previousServerId) {
                    CleanupWebsitePlacementJob::dispatch(
                        $locked->id,
                        $previousServerId,
                        $locked->deployment_slug,
                    )->afterCommit();
                }
            }

            return true;
        }, 5);
    }
}
