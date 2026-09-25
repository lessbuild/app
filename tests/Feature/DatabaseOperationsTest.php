<?php

namespace Tests\Feature;

use App\Modules\Deployer\Jobs\Database\CloneDatabaseJob;
use App\Modules\Deployer\Jobs\Database\CollectDatabaseSnapshotJob;
use App\Modules\Deployer\Jobs\Database\ManageDatabaseUserJob;
use App\Modules\Deployer\Models\DatabaseClone;
use App\Modules\Deployer\Models\DatabaseOperationRun;
use App\Modules\Deployer\Models\EnvironmentResource;
use App\Modules\Deployer\Models\Provider;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Models\Website;
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
        $inspectionRun = DatabaseOperationRun::query()->where('operation', 'inspection')->sole();
        $this->assertSame(DatabaseOperationRun::QUEUED, $inspectionRun->status);
        Queue::assertPushed(CollectDatabaseSnapshotJob::class, fn (CollectDatabaseSnapshotJob $job): bool => $job->resourceId === $resource->id && $job->operationRunId === $inspectionRun->id);

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
        $applyRun = DatabaseOperationRun::query()->where('operation', 'user_apply')->sole();
        $this->assertSame(DatabaseOperationRun::QUEUED, $applyRun->status);
        Queue::assertPushed(ManageDatabaseUserJob::class, fn (ManageDatabaseUserJob $job): bool => $job->databaseUserId === $databaseUser->id && $job->action === 'apply' && $job->operationRunId === $applyRun->id);

        $this->actingAs($owner)->delete(route('databases.users.destroy', $databaseUser))
            ->assertSessionHas('success', 'Database user removal queued.');
        $removalRun = DatabaseOperationRun::query()->where('operation', 'user_remove')->sole();
        $this->assertSame(DatabaseOperationRun::QUEUED, $removalRun->status);
        Queue::assertPushed(ManageDatabaseUserJob::class, fn (ManageDatabaseUserJob $job): bool => $job->databaseUserId === $databaseUser->id && $job->action === 'remove' && $job->operationRunId === $removalRun->id);
    }

    public function test_database_operation_reconciliation_requeues_only_stale_durable_work(): void
    {
        [, $resource] = $this->infrastructure();
        Queue::fake();

        $staleQueued = DatabaseOperationRun::query()->create([
            'environment_resource_id' => $resource->id,
            'operation' => 'inspection',
            'status' => DatabaseOperationRun::QUEUED,
            'created_at' => now()->subMinutes(4),
            'updated_at' => now()->subMinutes(4),
        ]);
        $expiredRunning = DatabaseOperationRun::query()->create([
            'environment_resource_id' => $resource->id,
            'operation' => 'inspection',
            'status' => DatabaseOperationRun::RUNNING,
            'attempts' => 1,
            'started_at' => now()->subMinutes(35),
            'lease_expires_at' => now()->subMinutes(5),
            'created_at' => now()->subMinutes(36),
            'updated_at' => now()->subMinutes(35),
        ]);
        DatabaseOperationRun::query()->create([
            'environment_resource_id' => $resource->id,
            'operation' => 'inspection',
            'status' => DatabaseOperationRun::QUEUED,
        ]);
        DatabaseOperationRun::query()->create([
            'environment_resource_id' => $resource->id,
            'operation' => 'inspection',
            'status' => DatabaseOperationRun::FAILED,
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ]);

        $this->artisan('buildpusher:databases:reconcile-operations')
            ->assertSuccessful()
            ->expectsOutputToContain('checked 2, dispatch attempts 2, failed 0');

        Queue::assertPushed(CollectDatabaseSnapshotJob::class, 2);
        Queue::assertPushed(CollectDatabaseSnapshotJob::class, fn (CollectDatabaseSnapshotJob $job): bool => $job->operationRunId === $staleQueued->id);
        Queue::assertPushed(CollectDatabaseSnapshotJob::class, fn (CollectDatabaseSnapshotJob $job): bool => $job->operationRunId === $expiredRunning->id);
    }

    public function test_owner_can_retry_unapplied_database_credentials_without_revealing_the_password(): void
    {
        [$owner, $resource] = $this->infrastructure();
        $databaseUser = $resource->databaseUsers()->create([
            'created_by' => $owner->id,
            'username' => 'retry_reader',
            'password' => 'stored-encrypted-password',
            'privilege' => 'read',
        ]);
        Queue::fake();

        $this->actingAs($owner)->post(route('databases.users.retry', $databaseUser))
            ->assertSessionHas('success', 'Database user setup queued.')
            ->assertSessionMissing('databasePassword');

        $operationRun = DatabaseOperationRun::query()->where('operation', 'user_apply')->sole();
        $this->assertSame($databaseUser->id, $operationRun->subject_id);
        $this->assertSame(DatabaseOperationRun::QUEUED, $operationRun->status);
        Queue::assertPushed(ManageDatabaseUserJob::class, fn (ManageDatabaseUserJob $job): bool => $job->databaseUserId === $databaseUser->id
            && $job->action === 'apply'
            && $job->operationRunId === $operationRun->id);

        $this->actingAs($owner)->post(route('databases.users.retry', $databaseUser))
            ->assertSessionHas('success', 'Database user setup is already underway.');
        Queue::assertPushed(ManageDatabaseUserJob::class, 1);
        $this->assertSame('stored-encrypted-password', $databaseUser->fresh()->password);
    }

    public function test_database_management_is_open_for_first_use_and_collapsed_after_setup(): void
    {
        [$owner, $resource] = $this->infrastructure();

        $firstUse = $this->actingAs($owner)->get(route('databases.index'));
        $this->assertMatchesRegularExpression(
            '/<details id="database-management-'.$resource->id.'"[^>]*\bopen\b[^>]*>/',
            $firstUse->getContent(),
        );

        $resource->databaseUsers()->create([
            'created_by' => $owner->id,
            'username' => 'existing_reader',
            'privilege' => 'read',
            'password' => 'encrypted-password',
            'expires_at' => null,
        ]);

        $completed = $this->actingAs($owner)->get(route('databases.index'));
        $this->assertDoesNotMatchRegularExpression(
            '/<details id="database-management-'.$resource->id.'"[^>]*\bopen\b[^>]*>/',
            $completed->getContent(),
        );
    }

    public function test_database_credential_composer_is_a_dialog_and_reopens_for_validation_errors(): void
    {
        [$owner, $resource] = $this->infrastructure();

        $default = $this->actingAs($owner)
            ->get(route('databases.index'))
            ->assertSuccessful()
            ->assertSee('data-modal-trigger="database-credential-'.$resource->id.'"', false);

        $this->assertDoesNotMatchRegularExpression(
            '/<dialog(?=[^>]*id="database-credential-'.$resource->id.'")(?=[^>]*\sopen(?:\s|>))[^>]*>/',
            $default->getContent(),
        );

        $dialogUrl = route('databases.index', [
            'dialog' => 'issue-credential',
            'resource_id' => $resource->id,
        ]);
        $this->assertMatchesRegularExpression(
            '/<dialog(?=[^>]*id="database-credential-'.$resource->id.'")(?=[^>]*\sopen(?:\s|>))[^>]*>/',
            $this->actingAs($owner)->get($dialogUrl)->assertSuccessful()->getContent(),
        );

        $response = $this->actingAs($owner)
            ->from($dialogUrl)
            ->followingRedirects()
            ->post(route('databases.users.store', $resource), [
                '_database_credential_resource' => $resource->id,
                'username' => 'not valid',
                'privilege' => 'invalid',
                'expires_in_days' => 2,
            ])
            ->assertSuccessful();

        $this->assertMatchesRegularExpression(
            '/<dialog(?=[^>]*id="database-credential-'.$resource->id.'")(?=[^>]*\sopen(?:\s|>))[^>]*>/',
            $response->getContent(),
        );
        $response->assertSee('The username format is invalid.');
        $this->assertDatabaseCount('database_users', 0);
    }

    public function test_viewer_can_inspect_but_cannot_issue_database_credentials(): void
    {
        [$owner, $resource] = $this->infrastructure();
        $viewer = User::factory()->create(['current_organization_id' => $owner->current_organization_id]);
        $owner->currentOrganization->members()->attach($viewer->id, ['role' => 'viewer']);
        Queue::fake();
        $databaseUser = $resource->databaseUsers()->create([
            'created_by' => $owner->id,
            'username' => 'owner_only_reader',
            'password' => 'encrypted-password',
            'privilege' => 'read',
        ]);

        $this->actingAs($viewer)->post(route('databases.inspect', $resource))
            ->assertSessionHas('success', 'Database inspection queued.');
        $this->actingAs($viewer)->post(route('databases.users.retry', $databaseUser))->assertForbidden();
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
        $this->assertSame(0, DatabaseOperationRun::query()->count());
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
