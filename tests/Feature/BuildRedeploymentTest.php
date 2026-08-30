<?php

namespace Tests\Feature;

use App\Jobs\Repository\PublishRepositoryJob;
use App\Models\Build;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BuildRedeploymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_redeploy_the_exact_revision_with_auditable_lineage(): void
    {
        Queue::fake();
        [$owner, $repository] = $this->repository();
        $repository->update(['setup_stage' => 6]);
        $revision = str_repeat('a', 40);
        $source = $repository->builds()->create([
            'status' => Build::STATUS_FAILED,
            'trigger_source' => Build::TRIGGER_WEBHOOK,
            'revision' => $revision,
            'commit_message' => 'Release candidate',
            'failure_message' => 'Dependency installation failed',
            'finished_at' => now(),
        ]);

        $response = $this->actingAs($owner)->post(route('builds.redeploy', $source));

        $redeployment = $repository->builds()->where('id', '!=', $source->id)->sole();
        $response
            ->assertRedirect(route('builds.show', $redeployment))
            ->assertSessionHas('success', 'Redeployment queued.');
        $this->assertSame(Build::STATUS_QUEUED, $redeployment->status);
        $this->assertSame(Build::TRIGGER_REDEPLOY, $redeployment->trigger_source);
        $this->assertSame($revision, $redeployment->revision);
        $this->assertSame('Release candidate', $redeployment->commit_message);
        $this->assertSame($source->id, $redeployment->redeployed_from_build_id);
        $this->assertTrue($redeployment->redeployedFrom->is($source));
        $this->assertSame(0, $repository->fresh()->setup_stage);
        Queue::assertPushed(PublishRepositoryJob::class, fn (PublishRepositoryJob $job): bool => $job->build->is($redeployment));

        $this->actingAs($owner)->get(route('builds.show', $redeployment))
            ->assertSuccessful()
            ->assertSee('Redeployment of')
            ->assertSee('Build #'.$source->id)
            ->assertSee('Redeploy');
    }

    public function test_redeployment_preserves_manual_branch_behavior_when_no_revision_was_recorded(): void
    {
        Queue::fake();
        [$owner, $repository] = $this->repository();
        $source = $repository->builds()->create([
            'status' => Build::STATUS_SUCCEEDED,
            'trigger_source' => Build::TRIGGER_MANUAL,
            'finished_at' => now(),
        ]);

        $this->actingAs($owner)->post(route('builds.redeploy', $source))->assertRedirect();

        $redeployment = $repository->builds()->where('id', '!=', $source->id)->sole();
        $this->assertNull($redeployment->revision);
        $this->assertSame(Build::TRIGGER_REDEPLOY, $redeployment->trigger_source);
        $this->actingAs($owner)->get(route('builds.show', $redeployment))
            ->assertSee('Current branch')
            ->assertDontSee('Redeploy this revision');
    }

    public function test_redeployment_is_rejected_while_another_deployment_is_active(): void
    {
        Queue::fake();
        [$owner, $repository] = $this->repository();
        $source = $repository->builds()->create([
            'status' => Build::STATUS_SUCCEEDED,
            'finished_at' => now(),
        ]);
        $repository->builds()->create(['status' => Build::STATUS_RUNNING]);

        $this->actingAs($owner)->post(route('builds.redeploy', $source))
            ->assertSessionHas('info', 'A deployment is already in progress.');

        $this->assertCount(2, $repository->fresh()->builds);
        Queue::assertNothingPushed();
    }

    public function test_only_terminal_builds_on_ready_infrastructure_can_be_redeployed(): void
    {
        Queue::fake();
        [$owner, $repository] = $this->repository();
        $queued = $repository->builds()->create(['status' => Build::STATUS_QUEUED]);

        $this->actingAs($owner)->post(route('builds.redeploy', $queued))
            ->assertSessionHas('info', 'Only completed, failed, or canceled deployments can be redeployed.');

        $queued->update(['status' => Build::STATUS_FAILED, 'finished_at' => now()]);
        $repository->website->update(['provisioning_status' => Website::STATUS_FAILED]);
        $this->actingAs($owner)->post(route('builds.redeploy', $queued))
            ->assertSessionHas('error', 'The website and server must be active before redeployment.');

        $this->assertDatabaseCount('builds', 1);
        Queue::assertNothingPushed();
    }

    public function test_other_users_cannot_redeploy_a_build(): void
    {
        Queue::fake();
        [, $repository] = $this->repository();
        $source = $repository->builds()->create([
            'status' => Build::STATUS_FAILED,
            'finished_at' => now(),
        ]);

        $this->actingAs(User::factory()->create())
            ->post(route('builds.redeploy', $source))
            ->assertForbidden();

        $this->assertDatabaseCount('builds', 1);
        Queue::assertNothingPushed();
    }

    /** @return array{User, Repository} */
    private function repository(): array
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create([
            'name' => 'GitHub',
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'source-secret',
            'description' => 'Source provider',
        ]);
        $server = $owner->servers()->create([
            'name' => 'Production',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $owner->websites()->create([
            'server_id' => $server->id,
            'name' => 'Application',
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
            'url' => 'app.example.com',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $repository = $owner->repositories()->create([
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => 'Application repository',
            'url' => 'github.com/example/application.git',
            'branch' => 'main',
            'description' => 'Application source',
        ]);

        return [$owner, $repository];
    }
}
