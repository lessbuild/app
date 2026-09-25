<?php

namespace Tests\Unit\Core;

use App\Core\Data\Projects\ProjectProductSnapshot;
use App\Core\Data\Projects\ProjectProductSnapshotState;
use App\Core\Data\Projects\ProjectSetupStep;
use App\Core\Data\Projects\ProjectSetupStepState;
use App\Core\Data\Projects\WorkspaceProjectOperationalState;
use App\Core\Models\ProjectProduct;
use App\Core\Services\Projects\WorkspaceDashboardOperationalFilter;
use Tests\TestCase;

final class WorkspaceDashboardOperationalFilterTest extends TestCase
{
    public function test_operational_filters_distinguish_attention_setup_and_unavailable_snapshots(): void
    {
        $filter = app(WorkspaceDashboardOperationalFilter::class);
        $attention = collect([
            'monitor' => new ProjectProductSnapshot('Monitor', 'Incident open.', ProjectProductSnapshotState::Attention),
        ]);
        $setup = collect([
            'deployer.setup' => new ProjectSetupStep(
                'deployer.setup',
                'deployer',
                'Connect a server',
                'Add an authorized server.',
                ProjectSetupStepState::NeedsAction,
            ),
        ]);
        $unavailable = collect([
            'analytics' => new ProjectProductSnapshot('Analytics', 'Source unavailable.', ProjectProductSnapshotState::Unavailable),
        ]);

        $this->assertTrue($filter->matches(WorkspaceProjectOperationalState::Attention, $attention, collect(), collect(), false));
        $this->assertTrue($filter->matches(WorkspaceProjectOperationalState::Setup, collect(), $setup, collect(), false));
        $this->assertTrue($filter->matches(WorkspaceProjectOperationalState::Unavailable, $unavailable, collect(), collect(), false));
        $this->assertFalse($filter->matches(WorkspaceProjectOperationalState::Current, $attention, collect(), collect(), false));
    }

    public function test_current_requires_an_authorized_snapshot_and_failed_connections_are_attention(): void
    {
        $filter = app(WorkspaceDashboardOperationalFilter::class);
        $current = collect([
            'analytics' => new ProjectProductSnapshot('Analytics', 'No traffic yet.', ProjectProductSnapshotState::Empty),
        ]);

        $activeProduct = new ProjectProduct(['status' => 'active']);
        $provisioningProduct = new ProjectProduct(['status' => 'provisioning']);
        $failedProduct = new ProjectProduct(['status' => 'failed']);

        $this->assertTrue($filter->matches(WorkspaceProjectOperationalState::All, collect(), collect(), collect(), false));
        $this->assertFalse($filter->matches(WorkspaceProjectOperationalState::Current, collect(), collect(), collect(), false));
        $this->assertTrue($filter->matches(WorkspaceProjectOperationalState::Current, $current, collect(), collect([$activeProduct]), false));
        $this->assertFalse($filter->matches(WorkspaceProjectOperationalState::Current, $current, collect(), collect([$provisioningProduct]), false));
        $this->assertTrue($filter->matches(WorkspaceProjectOperationalState::Setup, collect(), collect(), collect([$provisioningProduct]), false));
        $this->assertTrue($filter->matches(WorkspaceProjectOperationalState::Attention, collect(), collect(), collect([$failedProduct]), false));
        $this->assertTrue($filter->matches(WorkspaceProjectOperationalState::Attention, $current, collect(), collect(), true));
    }
}
