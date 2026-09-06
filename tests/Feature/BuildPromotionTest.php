<?php

namespace Tests\Feature;

use App\Actions\Repository\PromoteBuildAction;
use App\Data\BuildPromotionResult;
use App\Jobs\Repository\PublishRepositoryJob;
use App\Models\Build;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Scripts\Repository\CheckoutRepositoryScript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BuildPromotionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.enforce_entitlements' => false]);
    }

    public function test_successful_revision_is_promoted_with_lineage_target_snapshot_and_approval_gate(): void
    {
        Queue::fake();
        [$owner, $project, $staging, $production, $source] = $this->pipeline();
        $production->variables()->create(['key' => 'APP_MODE', 'value' => 'production', 'scope' => 'all', 'is_secret' => true, 'current_version' => 1, 'updated_by' => $owner->id]);

        $this->actingAs($owner)->post(route('builds.promote', $source), [
            'target_environment_id' => $production->id,
            'promotion_note' => ' CHG-204 verified in staging. ',
        ])->assertRedirect()->assertSessionHas('success', 'Release promotion requested.');

        $promoted = Build::query()->where('promoted_from_build_id', $source->id)->sole();
        $this->assertSame(Build::TRIGGER_PROMOTION, $promoted->trigger_source);
        $this->assertSame(Build::STATUS_AWAITING_APPROVAL, $promoted->status);
        $this->assertSame($production->id, $promoted->environment_id);
        $this->assertSame($source->revision, $promoted->revision);
        $this->assertSame('CHG-204 verified in staging.', $promoted->promotion_note);
        $this->assertSame('production', $promoted->environment_payload['variables']['APP_MODE']);
        $this->assertNotSame($promoted->promotion_note, DB::table('builds')->whereKey($promoted->id)->value('promotion_note'));
        $checkout = (new CheckoutRepositoryScript)->script(2, $promoted);
        $this->assertStringContainsString("merge-base --is-ancestor '".$source->revision."' 'origin/main'", $checkout);
        $this->assertStringContainsString("checkout --detach --force '".$source->revision."'", $checkout);
        Queue::assertNotPushed(PublishRepositoryJob::class);
        $this->assertSame('info', $owner->notifications()->sole()->data['status']);
        $this->assertSame($promoted->id, $owner->notifications()->sole()->data['resource_id']);

        $production->update(['deployment_locked_at' => now(), 'deployment_lock_reason' => 'Release freeze']);
        $this->actingAs($owner)->post(route('builds.approve', $promoted), ['approval_note' => 'Approved'])->assertSessionHas('info');
        $this->assertSame(Build::STATUS_AWAITING_APPROVAL, $promoted->fresh()->status);
        $this->assertNull($owner->notifications()->sole()->read_at);
        Queue::assertNotPushed(PublishRepositoryJob::class);
        $production->update(['deployment_locked_at' => null, 'deployment_lock_reason' => null]);
        $this->actingAs($owner)->post(route('builds.approve', $promoted), ['approval_note' => 'Approved'])->assertRedirect();
        Queue::assertPushed(PublishRepositoryJob::class, fn ($job): bool => $job->build->is($promoted));
        $this->assertNotNull($owner->notifications()->sole()->read_at);
        $this->actingAs($owner)->get(route('builds.show', $promoted))->assertOk()->assertSee('Promoted release')->assertSee('CHG-204 verified in staging.');
        $this->actingAs($owner)->get(route('builds.show', $source))->assertOk()->assertSee('Promotion history')->assertSee('#'.$promoted->id);
        $this->actingAs($owner)->get(route('projects.show', $project))->assertOk()->assertSee('Promote tested release')->assertSee($source->shortRevision());
    }

    public function test_promotion_requires_forward_same_project_same_source_ready_unblocked_target(): void
    {
        Queue::fake();
        [$owner, , $staging, $production, $source, $targetRepository] = $this->pipeline();
        $action = app(PromoteBuildAction::class);

        $this->assertSame(BuildPromotionResult::INELIGIBLE, $action->handle($source, $staging, $owner)->status);
        $targetRepository->update(['url' => 'github.com/example/another.git']);
        $this->assertSame(BuildPromotionResult::INCOMPATIBLE, $action->handle($source, $production, $owner)->status);
        $targetRepository->update(['url' => 'github.com/example/app.git']);
        $production->update(['deployment_locked_at' => now(), 'deployment_lock_reason' => 'Freeze']);
        $this->assertSame(BuildPromotionResult::BLOCKED, $action->handle($source, $production, $owner)->status);
        $production->update(['deployment_locked_at' => null, 'deployment_lock_reason' => null]);
        $targetRepository->website->update(['provisioning_status' => Website::STATUS_FAILED]);
        $this->assertSame(BuildPromotionResult::UNAVAILABLE, $action->handle($source, $production, $owner)->status);
        $this->assertSame(0, Build::query()->where('trigger_source', Build::TRIGGER_PROMOTION)->count());
    }

    public function test_target_serialization_prevents_duplicate_promotions_and_tenant_escape(): void
    {
        Queue::fake();
        [$owner, , , $production, $source, $targetRepository] = $this->pipeline();
        $targetRepository->builds()->create(['status' => Build::STATUS_RUNNING, 'trigger_source' => Build::TRIGGER_MANUAL, 'environment_id' => $production->id]);
        $this->assertSame(BuildPromotionResult::ACTIVE, app(PromoteBuildAction::class)->handle($source, $production, $owner)->status);

        $intruder = User::factory()->create();
        $this->actingAs($intruder)->post(route('builds.promote', $source), ['target_environment_id' => $production->id])->assertForbidden();
        $this->assertSame(BuildPromotionResult::INELIGIBLE, app(PromoteBuildAction::class)->handle($source, $production, $intruder)->status);
        $this->assertSame(0, Build::query()->where('trigger_source', Build::TRIGGER_PROMOTION)->count());
    }

    public function test_developer_can_request_but_cannot_approve_a_production_promotion(): void
    {
        Queue::fake();
        [$owner, , , $production, $source] = $this->pipeline();
        $developer = User::factory()->create();
        $owner->currentOrganization->members()->attach($developer, ['role' => 'developer']);
        $developer->update(['current_organization_id' => $owner->current_organization_id]);

        $this->actingAs($developer)->post(route('builds.promote', $source), ['target_environment_id' => $production->id])->assertRedirect();
        $promotion = Build::query()->where('trigger_source', Build::TRIGGER_PROMOTION)->sole();
        $this->assertSame($developer->id, $promotion->requested_by);
        $this->assertSame($developer->id, $promotion->events()->sole()->user_id);
        $this->actingAs($developer)->post(route('builds.approve', $promotion))->assertForbidden();
        $this->assertSame(Build::STATUS_AWAITING_APPROVAL, $promotion->fresh()->status);
        $this->actingAs($owner)->post(route('builds.reject', $promotion), ['approval_note' => 'Not ready'])->assertRedirect();
        $this->assertSame(Build::STATUS_REJECTED, $promotion->fresh()->status);
        $this->assertNotNull($owner->notifications()->sole()->read_at);
        $decision = $promotion->events()->where('event', 'Release promotion was rejected.')->sole();
        $this->assertSame($owner->id, $decision->user_id);
    }

    public function test_scoped_api_can_request_promotion_and_reports_lineage(): void
    {
        Queue::fake();
        [$owner, , , $production, $source] = $this->pipeline();
        Sanctum::actingAs($owner, ['deploy']);

        $this->postJson('/api/v1/deployments/'.$source->id.'/promote', [
            'target_environment_id' => $production->id,
            'promotion_note' => 'API release',
        ])->assertStatus(202)
            ->assertJsonPath('data.status', BuildPromotionResult::QUEUED)
            ->assertJsonPath('data.deployment.promoted_from_build_id', $source->id)
            ->assertJsonPath('data.deployment.revision', $source->revision);
    }

    private function pipeline(): array
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create(['name' => 'GitHub', 'description' => 'Source', 'provider' => Provider::TYPE_GITHUB, 'token' => 'token']);
        $project = $owner->currentOrganization->projects()->create(['created_by' => $owner->id, 'name' => 'App', 'slug' => 'app']);
        [$staging, $stagingRepository] = $this->environment($owner, $project, $provider, 'Staging', 'staging', 'develop', false);
        [$production, $productionRepository] = $this->environment($owner, $project, $provider, 'Production', 'production', 'main', true);
        $source = $stagingRepository->builds()->create([
            'status' => Build::STATUS_SUCCEEDED,
            'trigger_source' => Build::TRIGGER_MANUAL,
            'environment_id' => $staging->id,
            'revision' => str_repeat('a', 40),
            'commit_message' => 'Tested release',
            'finished_at' => now(),
        ]);

        return [$owner, $project, $staging, $production, $source, $productionRepository];
    }

    private function environment(User $owner, $project, Provider $provider, string $name, string $type, string $branch, bool $approval): array
    {
        $server = $owner->servers()->create(['name' => $name, 'public_ip' => $type === 'production' ? '203.0.113.20' : '203.0.113.10', 'ssh_private_key' => 'key', 'provisioning_status' => Server::STATUS_ACTIVE]);
        $website = $owner->websites()->create(['server_id' => $server->id, 'name' => $name, 'description' => $name, 'environment' => '', 'url' => strtolower($name).'.example.com', 'provisioning_status' => Website::STATUS_ACTIVE]);
        $repository = $owner->repositories()->create(['provider_id' => $provider->id, 'website_id' => $website->id, 'name' => 'App', 'url' => 'github.com/example/app.git', 'branch' => $branch, 'description' => 'Source']);
        $environment = $project->environments()->create(['server_id' => $server->id, 'website_id' => $website->id, 'name' => $name, 'slug' => strtolower($name), 'type' => $type, 'branch' => $branch, 'requires_deployment_approval' => $approval]);

        return [$environment, $repository];
    }
}
