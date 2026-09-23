<?php

namespace Tests\Feature\Core;

use App\Core\Data\Projects\ProjectProductSnapshot;
use App\Core\Data\Projects\ProjectProductSnapshotState;
use App\Core\Data\Projects\ProjectSetupStep;
use App\Core\Data\Projects\ProjectSetupStepState;
use App\Core\Models\Project;
use App\Core\Models\ProjectConnection;
use App\Core\Models\Workspace;
use App\Core\Services\Projects\WorkspaceDashboardPriorities;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WorkspaceDashboardPrioritiesTest extends TestCase
{
    public function test_priorities_order_connection_failures_before_health_and_setup_and_keep_codes_redacted(): void
    {
        $workspace = (new Workspace)->forceFill(['id' => (string) Str::ulid(), 'name' => 'Northstar']);
        $project = (new Project)->forceFill(['id' => (string) Str::ulid(), 'workspace_id' => $workspace->getKey(), 'name' => 'Checkout']);
        $connection = (new ProjectConnection)->forceFill([
            'id' => (string) Str::ulid(),
            'project_id' => $project->getKey(),
            'status' => 'failed',
            'last_error_code' => 'product_access_changed',
            'last_error_at' => now()->subMinutes(3),
        ]);
        $summaries = collect([
            'monitor' => new ProjectProductSnapshot(
                title: 'Monitor health and incidents',
                detail: '2 open incidents',
                state: ProjectProductSnapshotState::Attention,
            ),
        ]);
        $setupSteps = collect([
            'deployer.repository' => new ProjectSetupStep(
                id: 'deployer.repository',
                product: 'deployer',
                title: 'Connect a source repository',
                detail: 'Connect a repository to this project.',
                state: ProjectSetupStepState::NeedsAction,
            ),
        ]);

        $priorities = app(WorkspaceDashboardPriorities::class)->forProject(
            $workspace,
            $project,
            $summaries,
            $setupSteps,
            collect([$connection]),
        );

        $this->assertSame([2, 3, 4], $priorities->pluck('rank')->all());
        $this->assertSame('Access changed', $priorities[0]->badge);
        $this->assertSame('2 open incidents', $priorities[1]->detail);
        $this->assertSame('Connect a source repository', $priorities[2]->title);
        $this->assertStringContainsString('#connections', $priorities[0]->actionUrl);
        $this->assertStringContainsString('#setup', $priorities[2]->actionUrl);
        $this->assertStringNotContainsString('product_access_changed', $priorities[0]->detail);
    }
}
