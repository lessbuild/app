<?php

namespace Tests\Feature;

use App\Jobs\Repository\PublishRepositoryJob;
use App\Jobs\Repository\RollbackReleaseJob;
use App\Models\Build;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Scripts\Repository\ActivateReleaseScript;
use App\Scripts\Repository\ArtisanCommandsScript;
use App\Scripts\Repository\SymlinkScript;
use App\Services\RepositoryDeploymentPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class DeploymentApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_protected_environment_waits_for_an_administrator_before_dispatching(): void
    {
        Queue::fake();
        [$owner, $repository] = $this->protectedRepository();

        $this->actingAs($owner)->post(route('repositories.deploy', $repository))
            ->assertSessionHas('success', 'Deployment submitted for approval');

        $build = $repository->builds()->sole();
        $this->assertSame(Build::STATUS_AWAITING_APPROVAL, $build->status);
        $this->assertSame($owner->id, $build->requested_by);
        $this->assertNotNull($build->environment_id);
        Queue::assertNothingPushed();

        $this->actingAs($owner)->post(route('builds.approve', $build), [
            'approval_note' => 'Change CHG-104 is approved.',
        ])->assertSessionHas('success', 'Deployment approved and queued.');

        $build->refresh();
        $this->assertSame(Build::STATUS_QUEUED, $build->status);
        $this->assertSame($owner->id, $build->approved_by);
        $this->assertNotNull($build->approved_at);
        $this->assertSame('Change CHG-104 is approved.', $build->approval_note);
        Queue::assertPushed(PublishRepositoryJob::class, fn (PublishRepositoryJob $job): bool => $job->build->is($build));
    }

    public function test_developer_cannot_approve_and_administrator_can_reject(): void
    {
        Queue::fake();
        [$owner, $repository] = $this->protectedRepository();
        $developer = User::factory()->create();
        $repository->organization->members()->attach($developer, ['role' => 'developer']);
        $developer->update(['current_organization_id' => $repository->organization_id]);

        $this->actingAs($developer)->post(route('repositories.deploy', $repository));
        $build = $repository->builds()->sole();

        $this->actingAs($developer)->post(route('builds.approve', $build))->assertForbidden();
        $this->actingAs($owner)->post(route('builds.reject', $build), [
            'approval_note' => 'Missing QA sign-off.',
        ])->assertSessionHas('success', 'Deployment rejected.');

        $build->refresh();
        $this->assertSame(Build::STATUS_REJECTED, $build->status);
        $this->assertSame($owner->id, $build->rejected_by);
        $this->assertNotNull($build->rejected_at);
        Queue::assertNothingPushed();
    }

    public function test_release_is_prepared_before_the_atomic_activation_and_records_activation(): void
    {
        [, $repository] = $this->protectedRepository();
        $build = $repository->builds()->create([
            'status' => Build::STATUS_RUNNING,
            'release_name' => '20260905010101-build-42',
        ]);
        $scripts = app(RepositoryDeploymentPlan::class)->scripts();

        $this->assertLessThan(array_search(ActivateReleaseScript::class, $scripts, true), array_search(SymlinkScript::class, $scripts, true));
        $this->assertLessThan(array_search(ActivateReleaseScript::class, $scripts, true), array_search(ArtisanCommandsScript::class, $scripts, true));
        $this->assertStringContainsString("cd -- '/var/www/{$repository->website->deployment_slug}/setup'", (new ArtisanCommandsScript)->script(1, $build));
        $this->assertStringContainsString("RELEASE_NAME='20260905010101-build-42'", (new ActivateReleaseScript)->script(1, $build));

        $this->post(URL::signedRoute('callbacks.build.status', $build), [
            'status' => app(RepositoryDeploymentPlan::class)->activationStage(),
        ])->assertNoContent();

        $this->assertNotNull($build->fresh()->activated_at);
    }

    public function test_owner_can_queue_an_instant_rollback_to_a_retained_artifact(): void
    {
        Queue::fake();
        [$owner, $repository] = $this->protectedRepository();
        $environment = $repository->website->environments()->sole();
        $source = $repository->builds()->create([
            'status' => Build::STATUS_SUCCEEDED,
            'trigger_source' => Build::TRIGGER_MANUAL,
            'environment_id' => $environment->id,
            'revision' => str_repeat('a', 40),
            'release_name' => '20260905010101-build-42',
            'release_path' => "/var/www/{$repository->website->deployment_slug}/releases/20260905010101-build-42",
            'activated_at' => now()->subMinute(),
        ]);

        $response = $this->actingAs($owner)->post(route('builds.rollback', $source));
        $rollback = $repository->builds()->whereKeyNot($source->id)->sole();

        $response->assertRedirect(route('builds.show', $rollback));
        $this->assertSame(Build::TRIGGER_ROLLBACK, $rollback->trigger_source);
        $this->assertSame($source->id, $rollback->rolled_back_from_build_id);
        $this->assertSame($source->release_path, $rollback->release_path);
        $this->assertSame($owner->id, $rollback->approved_by);
        Queue::assertPushed(RollbackReleaseJob::class, fn (RollbackReleaseJob $job): bool => $job->build->is($rollback));
    }

    /** @return array{User, Repository} */
    private function protectedRepository(): array
    {
        $owner = User::factory()->create();
        $organization = $owner->currentOrganization;
        $provider = $owner->providers()->create([
            'name' => 'GitHub',
            'provider' => 'github',
            'token' => 'secret',
            'description' => 'Git provider',
        ]);
        $server = $owner->servers()->create([
            'name' => 'Production',
            'provider_id' => $provider->id,
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $owner->websites()->create([
            'server_id' => $server->id,
            'name' => 'Application',
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
            'url' => 'app.test',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $repository = $owner->repositories()->create([
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => 'Application repository',
            'url' => 'github.com/example/app.git',
            'branch' => 'main',
            'description' => 'Repository',
        ]);
        $project = $organization->projects()->create([
            'created_by' => $owner->id,
            'name' => 'Application',
            'slug' => 'application',
        ]);
        $project->environments()->create([
            'name' => 'Production',
            'slug' => 'production',
            'type' => 'production',
            'branch' => 'main',
            'server_id' => $server->id,
            'website_id' => $website->id,
            'is_protected' => true,
            'requires_deployment_approval' => true,
        ]);

        return [$owner, $repository];
    }
}
