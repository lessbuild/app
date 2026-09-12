<?php

namespace Tests\Feature;

use App\Jobs\Database\CloneDatabaseJob;
use App\Jobs\Database\CollectDatabaseSnapshotJob;
use App\Jobs\Database\ManageDatabaseUserJob;
use App\Models\DatabaseClone;
use App\Models\EnvironmentResource;
use App\Models\Provider;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DatabaseOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.enforce_entitlements' => false, 'billing.enforce_limits' => false]);
        Cache::flush();
    }

    public function test_owner_can_queue_inspection_and_issue_an_encrypted_database_credential(): void
    {
        [$owner, $resource] = $this->infrastructure();
        Queue::fake();

        $this->actingAs($owner)->post(route('databases.inspect', $resource))
            ->assertSessionHas('success', 'Database inspection queued.');
        Queue::assertPushed(CollectDatabaseSnapshotJob::class, fn (CollectDatabaseSnapshotJob $job): bool => $job->resourceId === $resource->id);

        $response = $this->actingAs($owner)->post(route('databases.users.store', $resource), [
            'username' => 'report_reader',
            'privilege' => 'read',
            'expires_in_days' => 7,
        ]);
        $response->assertSessionHas('success', 'Database user queued. Copy the password now; it will not be shown again.');
        $password = $response->getSession()->get('databasePassword');
        $databaseUser = $resource->databaseUsers()->sole();

        $this->assertIsString($password);
        $this->assertSame($password, $databaseUser->password);
        $this->assertNotSame($password, DB::table('database_users')->value('password'));
        $this->assertEqualsWithDelta(7, now()->diffInDays($databaseUser->expires_at), 0.001);
        Queue::assertPushed(ManageDatabaseUserJob::class, fn (ManageDatabaseUserJob $job): bool => $job->databaseUserId === $databaseUser->id && $job->action === 'apply');

        $this->actingAs($owner)->delete(route('databases.users.destroy', $databaseUser))
            ->assertSessionHas('success', 'Database user removal queued.');
        Queue::assertPushed(ManageDatabaseUserJob::class, fn (ManageDatabaseUserJob $job): bool => $job->databaseUserId === $databaseUser->id && $job->action === 'remove');
    }

    public function test_viewer_can_inspect_but_cannot_issue_database_credentials(): void
    {
        [$owner, $resource] = $this->infrastructure();
        $viewer = User::factory()->create(['current_organization_id' => $owner->current_organization_id]);
        $owner->currentOrganization->members()->attach($viewer->id, ['role' => 'viewer']);
        Queue::fake();

        $this->actingAs($viewer)->post(route('databases.inspect', $resource))
            ->assertSessionHas('success', 'Database inspection queued.');
        $this->actingAs($viewer)->post(route('databases.users.store', $resource), [
            'username' => 'not allowed',
            'privilege' => 'invalid',
        ])->assertForbidden();

        $this->assertDatabaseCount('database_users', 0);
        Queue::assertNotPushed(ManageDatabaseUserJob::class);
    }

    public function test_unsupported_resources_return_the_existing_unavailable_response_before_writes(): void
    {
        [$owner, $resource] = $this->infrastructure('redis');
        Queue::fake();

        $this->actingAs($owner)->post(route('databases.inspect', $resource))->assertStatus(422);
        $this->actingAs($owner)->post(route('databases.users.store', $resource), [
            'username' => 'valid_name',
            'privilege' => 'read',
        ])->assertStatus(422);

        $this->assertDatabaseCount('database_users', 0);
        Queue::assertNothingPushed();
    }

    public function test_clone_requires_confirmation_and_preserves_target_safety_rules(): void
    {
        [$owner, $source] = $this->infrastructure();
        $project = $source->environment->project;
        $targetEnvironment = $project->environments()->create([
            'name' => 'Staging', 'slug' => 'staging', 'type' => 'staging', 'branch' => 'main',
        ]);
        $target = $targetEnvironment->resources()->create([
            'name' => 'staging_database', 'type' => $source->type, 'is_managed' => true,
            'status' => 'ready', 'configuration' => ['variables' => []],
        ]);
        Queue::fake();

        $this->actingAs($owner)->post(route('databases.clone', $source), [
            'target_resource_id' => $target->id, 'confirmation' => 'wrong',
        ])->assertSessionHasErrors('confirmation');
        $this->assertDatabaseCount('database_clones', 0);
        Queue::assertNothingPushed();

        $this->actingAs($owner)->post(route('databases.clone', $source), [
            'target_resource_id' => $target->id, 'confirmation' => $target->name,
        ])->assertSessionHas('success', 'Database clone queued. The target will be replaced.');
        $clone = DatabaseClone::query()->sole();
        $this->assertDatabaseHas('database_clones', [
            'source_resource_id' => $source->id,
            'target_resource_id' => $target->id,
            'status' => 'queued',
        ]);
        Queue::assertPushed(CloneDatabaseJob::class, fn (CloneDatabaseJob $job): bool => $job->cloneId === $clone->id);
    }

    public function test_production_and_foreign_clone_targets_are_rejected_without_a_clone(): void
    {
        [$owner, $source] = $this->infrastructure();
        $project = $source->environment->project;
        $productionEnvironment = $project->environments()->create([
            'name' => 'Another production', 'slug' => 'another-production', 'type' => 'production', 'branch' => 'main',
        ]);
        $productionTarget = $productionEnvironment->resources()->create([
            'name' => 'production_target', 'type' => $source->type, 'is_managed' => true,
            'status' => 'ready', 'configuration' => ['variables' => []],
        ]);
        Queue::fake();

        $this->actingAs($owner)->post(route('databases.clone', $source), [
            'target_resource_id' => $productionTarget->id, 'confirmation' => $productionTarget->name,
        ])->assertStatus(422);

        [, $foreignTarget] = $this->infrastructure();
        $this->actingAs($owner)->post(route('databases.clone', $source), [
            'target_resource_id' => $foreignTarget->id, 'confirmation' => $foreignTarget->name,
        ])->assertForbidden();

        $this->assertDatabaseCount('database_clones', 0);
        Queue::assertNothingPushed();
    }

    /** @return array{User, EnvironmentResource} */
    private function infrastructure(string $type = 'postgresql'): array
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create([
            'name' => 'Cloud', 'provider' => Provider::TYPE_DIGITALOCEAN,
            'token' => 'token', 'description' => 'Infrastructure',
        ]);
        $server = $owner->servers()->create([
            'provider_id' => $provider->id, 'name' => 'Node', 'public_ip' => '203.0.113.10',
            'ssh_private_key' => 'key', 'provisioning_status' => 'active',
        ]);
        $website = $owner->websites()->create([
            'server_id' => $server->id, 'name' => 'Application', 'url' => 'app.example.com',
            'description' => 'Application', 'environment' => '', 'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $project = $owner->currentOrganization->projects()->create([
            'created_by' => $owner->id, 'name' => 'Application', 'slug' => 'application-'.str()->random(6),
        ]);
        $environment = $project->environments()->create([
            'name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main',
            'server_id' => $server->id, 'website_id' => $website->id,
        ]);
        $resource = $environment->resources()->create([
            'name' => 'primary_database', 'type' => $type, 'is_managed' => true,
            'status' => 'ready', 'configuration' => ['variables' => []],
        ]);

        return [$owner, $resource];
    }
}
