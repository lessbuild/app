<?php

namespace Tests\Unit;

use App\Modules\Deployer\Models\Website;
use App\Modules\Deployer\Services\WebsiteProvisioningPlan;
use App\Modules\Deployer\Services\WebsiteProvisioningTimeline;
use Tests\TestCase;

class WebsiteProvisioningTimelineTest extends TestCase
{
    public function test_provisioning_progress_marks_the_next_stage_active(): void
    {
        $website = new Website([
            'provisioning_status' => Website::STATUS_PROVISIONING,
            'setup_stage' => 1,
        ]);

        $entries = app(WebsiteProvisioningTimeline::class)->for($website);
        $statuses = array_column($entries, 'status', 'key');

        $this->assertSame(WebsiteProvisioningTimeline::STATUS_COMPLETED, $statuses['requested']);
        $this->assertSame(WebsiteProvisioningTimeline::STATUS_COMPLETED, $statuses['added-website']);
        $this->assertSame(WebsiteProvisioningTimeline::STATUS_ACTIVE, $statuses['created-mysql-database']);
        $this->assertSame(WebsiteProvisioningTimeline::STATUS_PENDING, $statuses['updated-env']);
    }

    public function test_failed_provisioning_marks_only_the_next_uncompleted_stage_failed(): void
    {
        $website = new Website([
            'provisioning_status' => Website::STATUS_FAILED,
            'setup_stage' => 1,
        ]);

        $entries = app(WebsiteProvisioningTimeline::class)->for($website);
        $statuses = array_column($entries, 'status', 'key');

        $this->assertSame(WebsiteProvisioningTimeline::STATUS_COMPLETED, $statuses['added-website']);
        $this->assertSame(WebsiteProvisioningTimeline::STATUS_FAILED, $statuses['created-mysql-database']);
        $this->assertSame(WebsiteProvisioningTimeline::STATUS_PENDING, $statuses['updated-env']);
    }

    public function test_completed_provisioning_uses_the_real_completion_timestamp(): void
    {
        $completedAt = now()->subMinute();
        $website = new Website([
            'provisioning_status' => Website::STATUS_ACTIVE,
            'setup_stage' => app(WebsiteProvisioningPlan::class)->finalStage(),
            'provisioned_at' => $completedAt,
        ]);

        $entries = app(WebsiteProvisioningTimeline::class)->for($website);
        $final = $entries[array_key_last($entries)];

        $this->assertSame(WebsiteProvisioningTimeline::STATUS_COMPLETED, $final->status);
        $this->assertSame($completedAt->toDateTimeString(), $final->occurredAt?->toDateTimeString());
    }
}
