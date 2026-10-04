<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Jobs\Security\PatchServer;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\ServerSnapshot;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class ServerSnapshotsTest extends TestCase
{
    use InfrastructureHelpers, MonitoringHelpers {
        MonitoringHelpers::ownerOf insteadof InfrastructureHelpers;
    }
    use RefreshDatabase;

    /**
     * Check a server set to snapshot before risky changes gets one before updates are installed (and not when it
     * isn't set to), one can be taken by hand, only the three newest are kept, and imported servers can't.
     *
     * @return void
     */
    public function test_snapshots_are_taken_before_risky_changes(): void
    {
        $this->fakeInfrastructure();
        $this->withoutMiddleware(RequirePassword::class);
        $project = Project::factory()->withServices(['infrastructure'])->create();
        $owner = $this->ownerOf($project);
        $server = Server::factory()->create(['account_id' => $project->account_id, 'name' => 'web-1', 'identifier' => '4242', 'provisioning_status' => Server::STATUS_ACTIVE, 'provider_id' => Provider::factory()->create(['account_id' => $project->account_id])->id]);
        $url = "/api/app/projects/{$project->id}/infrastructure/servers/{$server->id}/snapshots";

        PatchServer::dispatch($server->id, false);
        $this->assertSame([], $this->cloud->snapshots, 'Off by default.');

        $this->actingAs($owner)->putJson($url, ['snapshot_before_changes' => '1'])->assertSuccessful();
        $this->assertTrue($server->refresh()->snapshot_before_changes);
        $ran = count($this->shell->ran);
        PatchServer::dispatch($server->id, false);
        $this->assertSame('4242', (string) $this->cloud->snapshots[0][0]);
        $this->assertStringStartsWith('buildpusher-web-1-', $this->cloud->snapshots[0][1]);
        $this->assertCount($ran + 1, $this->shell->ran, 'The updates still ran after the snapshot.');
        $this->assertSame(['Before installing updates', 'snap-1', 'taken'], [ServerSnapshot::query()->sole()->reason, ServerSnapshot::query()->sole()->provider_snapshot, ServerSnapshot::query()->sole()->status]);

        foreach (range(1, 3) as $time) {
            $this->actingAs($owner)->postJson($url)->assertSuccessful();
        }
        $this->assertSame(['snap-1'], $this->cloud->deletedSnapshots, 'Only the three newest are kept.');
        $this->assertSame(3, ServerSnapshot::query()->where('status', 'taken')->count());
        $this->actingAs($owner)->getJson("/api/app/projects/{$project->id}/infrastructure/servers/{$server->id}")->assertOk()->assertJsonPath('server.snapshotBeforeChanges', true)->assertJsonPath('snapshots.0.reason', 'Taken by hand');

        $imported = Server::factory()->create(['account_id' => $project->account_id, 'provider_id' => null, 'provisioning_status' => Server::STATUS_ACTIVE]);
        $this->actingAs($owner)->putJson("/api/app/projects/{$project->id}/infrastructure/servers/{$imported->id}/snapshots", ['snapshot_before_changes' => '1'])->assertJsonValidationErrors('snapshot_before_changes');
    }
}
