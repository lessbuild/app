<?php

namespace App\Modules\Deployer\Services;

use App\Modules\Deployer\Data\DeploymentTimelineEntry;
use App\Modules\Deployer\Models\Website;

class WebsiteProvisioningTimeline
{
    public const STATUS_COMPLETED = 'completed';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELED = 'canceled';

    public const STATUS_PENDING = 'pending';

    public function __construct(private readonly WebsiteProvisioningPlan $plan) {}

    /**
     * Assemble provisioning milestones from the persisted website progress.
     *
     * Website callbacks persist a monotonic stage count but do not persist a
     * timestamp for every script. The timeline therefore exposes only the
     * state that can be established from the website row and keeps timestamps
     * limited to the provisioning completion timestamp.
     *
     * @return list<DeploymentTimelineEntry> Ordered provisioning milestones.
     */
    public function for(Website $website): array
    {
        $finalStage = $this->plan->finalStage();
        $progress = max(0, min($finalStage, (int) $website->setup_stage));
        $entries = [new DeploymentTimelineEntry(
            key: 'requested',
            title: 'Provisioning requested',
            description: 'The website and its assigned server were recorded for remote provisioning.',
            status: self::STATUS_COMPLETED,
            occurredAt: $website->created_at,
        )];

        foreach ($this->plan->scripts() as $index => $script) {
            $stage = $index + 1;
            $status = $this->statusFor($website, $stage, $progress, $finalStage);

            $entries[] = new DeploymentTimelineEntry(
                key: (string) ($script::$identifier ?? 'provisioning-'.$stage),
                title: (string) $script::$title,
                description: (string) $script::$description,
                status: $status,
                occurredAt: $status === self::STATUS_COMPLETED && $stage === $finalStage
                    ? $website->provisioned_at
                    : null,
            );
        }

        return $entries;
    }

    /**
     * Resolve the display state for one stage without inferring a failure phase
     * that the provisioning callback contract does not persist.
     *
     * @param  Website  $website  Website whose current lifecycle state is displayed.
     * @param  int  $stage  One-based provisioning stage.
     * @param  int  $progress  Normalized completed stage count.
     * @param  int  $finalStage  Number of stages in the current plan.
     * @return string Timeline status.
     */
    private function statusFor(Website $website, int $stage, int $progress, int $finalStage): string
    {
        if ($progress >= $stage) {
            return self::STATUS_COMPLETED;
        }

        if ($website->provisioning_status === Website::STATUS_FAILED) {
            return $stage === min($finalStage, $progress + 1)
                ? self::STATUS_FAILED
                : self::STATUS_PENDING;
        }

        if ($website->provisioning_status === self::STATUS_CANCELED) {
            return self::STATUS_CANCELED;
        }

        if ($website->provisioning_status === Website::STATUS_PROVISIONING
            && $stage === min($finalStage, $progress + 1)) {
            return self::STATUS_ACTIVE;
        }

        return self::STATUS_PENDING;
    }
}
