<?php

namespace Tests\Feature;

use App\Jobs\ApplyEnvironmentRuntimeStateJob;
use App\Jobs\RunScheduledTaskJob;
use App\Jobs\WakeHibernatedEnvironmentJob;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Services\Entitlements;
use App\Services\ManagedSsh;
use App\Services\Runner;
use App\Services\WorkflowConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AutomationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['billing.enforce_entitlements' => false]);
    }

    public function test_owner_can_create_a_deployment_schedule_with_a_validated_contract(): void
    {
        $user = User::factory()->create();
        $project = $user->currentOrganization->projects()->create(['created_by' => $user->id, 'name' => 'Scheduled', 'slug' => 'scheduled', 'preset' => 'custom']);
        $environment = $project->environments()->create(['name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main']);

        $this->actingAs($user)->post(route('automation.deployment-schedules.store', $environment), [
            'name' => 'Weekday deploy', 'cron_expression' => '0 3 * * 1-5', 'timezone' => 'UTC',
        ])->assertRedirect()->assertSessionHas('success', 'Deployment schedule created.');

        $schedule = $environment->deploymentSchedules()->sole();
        $this->assertSame('Weekday deploy', $schedule->name);
        $this->assertSame('0 3 * * 1-5', $schedule->cron_expression);
        $this->assertSame('UTC', $schedule->timezone);
        $this->assertTrue($schedule->is_enabled);
        $this->assertSame($user->id, $schedule->created_by);
    }

    public function test_deployment_schedule_denial_precedes_malformed_input_and_writes_nothing(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $owner->currentOrganization->members()->attach($viewer, ['role' => 'viewer']);
        $viewer->update(['current_organization_id' => $owner->current_organization_id]);
        $project = $owner->currentOrganization->projects()->create(['created_by' => $owner->id, 'name' => 'Scheduled', 'slug' => 'scheduled', 'preset' => 'custom']);
        $environment = $project->environments()->create(['name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main']);

        $this->actingAs($viewer)->post(route('automation.deployment-schedules.store', $environment), [])->assertForbidden();

        $this->assertDatabaseCount('deployment_schedules', 0);
    }

    public function test_free_plan_rejects_deployment_schedule_before_persistence(): void
    {
        config(['billing.enforce_entitlements' => true]);
        $user = User::factory()->create();
        $project = $user->currentOrganization->projects()->create(['created_by' => $user->id, 'name' => 'Scheduled', 'slug' => 'scheduled', 'preset' => 'custom']);
        $environment = $project->environments()->create(['name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main']);

        $this->actingAs($user)->post(route('automation.deployment-schedules.store', $environment), [
            'name' => 'Weekday deploy', 'cron_expression' => '0 3 * * 1-5', 'timezone' => 'UTC',
        ])->assertSessionHasErrors('plan');

        $this->assertDatabaseCount('deployment_schedules', 0);
    }

    public function test_owner_can_create_a_scaling_schedule_with_environment_replica_bounds(): void
    {
        $user = User::factory()->create();
        $project = $user->currentOrganization->projects()->create(['created_by' => $user->id, 'name' => 'Scaling', 'slug' => 'scaling', 'preset' => 'custom']);
        $environment = $project->environments()->create([
            'name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main',
            'minimum_replicas' => 2, 'maximum_replicas' => 6,
        ]);

        $this->actingAs($user)->post(route('automation.scaling-schedules.store', $environment), [
            'name' => 'Morning capacity', 'cron_expression' => '0 8 * * 1-5', 'timezone' => 'UTC', 'replicas' => 4,
        ])->assertRedirect()->assertSessionHas('success', 'Scaling schedule created.');

        $schedule = $environment->scalingSchedules()->sole();
        $this->assertSame('Morning capacity', $schedule->name);
        $this->assertSame('0 8 * * 1-5', $schedule->cron_expression);
        $this->assertSame(4, $schedule->replicas);
        $this->assertTrue($schedule->is_enabled);
        $this->assertSame($user->id, $schedule->created_by);
    }

    public function test_scaling_schedule_rejects_replica_values_outside_environment_bounds(): void
    {
        $user = User::factory()->create();
        $project = $user->currentOrganization->projects()->create(['created_by' => $user->id, 'name' => 'Scaling', 'slug' => 'scaling', 'preset' => 'custom']);
        $environment = $project->environments()->create([
            'name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main',
            'minimum_replicas' => 2, 'maximum_replicas' => 6,
        ]);

        $this->actingAs($user)->post(route('automation.scaling-schedules.store', $environment), [
            'name' => 'Too large', 'cron_expression' => '0 8 * * 1-5', 'timezone' => 'UTC', 'replicas' => 7,
        ])->assertSessionHasErrors('replicas');

        $this->assertDatabaseCount('scaling_schedules', 0);
    }

    public function test_scaling_schedule_denial_precedes_malformed_input_and_writes_nothing(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $owner->currentOrganization->members()->attach($viewer, ['role' => 'viewer']);
        $viewer->update(['current_organization_id' => $owner->current_organization_id]);
        $project = $owner->currentOrganization->projects()->create(['created_by' => $owner->id, 'name' => 'Scaling', 'slug' => 'scaling', 'preset' => 'custom']);
        $environment = $project->environments()->create(['name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main']);

        $this->actingAs($viewer)->post(route('automation.scaling-schedules.store', $environment), [])->assertForbidden();

        $this->assertDatabaseCount('scaling_schedules', 0);
    }

    public function test_free_plan_rejects_scaling_schedule_before_persistence(): void
    {
        config(['billing.enforce_entitlements' => true]);
        $user = User::factory()->create();
        $project = $user->currentOrganization->projects()->create(['created_by' => $user->id, 'name' => 'Scaling', 'slug' => 'scaling', 'preset' => 'custom']);
        $environment = $project->environments()->create(['name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main']);

        $this->actingAs($user)->post(route('automation.scaling-schedules.store', $environment), [
            'name' => 'Morning capacity', 'cron_expression' => '0 8 * * 1-5', 'timezone' => 'UTC', 'replicas' => 1,
        ])->assertSessionHasErrors('plan');

        $this->assertDatabaseCount('scaling_schedules', 0);
    }

    public function test_owner_can_update_environment_scaling_and_queue_the_runtime_transition(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $project = $user->currentOrganization->projects()->create(['created_by' => $user->id, 'name' => 'Scaling', 'slug' => 'scaling', 'preset' => 'custom']);
        $environment = $project->environments()->create([
            'name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main',
            'minimum_replicas' => 1, 'maximum_replicas' => 6, 'desired_replicas' => 1,
        ]);

        $this->actingAs($user)->patch(route('automation.scale', $environment), [
            'minimum_replicas' => 2,
            'maximum_replicas' => 5,
            'desired_replicas' => 4,
            'hibernate_after_minutes' => 60,
        ])->assertRedirect()->assertSessionHas('success', 'Scaling change queued.');

        $environment->refresh();
        $this->assertSame(2, $environment->minimum_replicas);
        $this->assertSame(5, $environment->maximum_replicas);
        $this->assertSame(4, $environment->desired_replicas);
        $this->assertSame(60, $environment->hibernate_after_minutes);
        $this->assertNull($environment->hibernated_at);
        Queue::assertPushed(ApplyEnvironmentRuntimeStateJob::class, fn (ApplyEnvironmentRuntimeStateJob $job): bool => $job->environmentId === $environment->id && $job->hibernate === false);
    }

    public function test_web_runtime_request_preserves_state_response_and_hibernation_entitlement(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $project = $user->currentOrganization->projects()->create(['created_by' => $user->id, 'name' => 'Runtime', 'slug' => 'runtime', 'preset' => 'custom']);
        $environment = $project->environments()->create(['name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main']);

        $this->actingAs($user)->patch(route('automation.runtime', $environment), ['state' => 'hibernated'])
            ->assertRedirect()->assertSessionHas('success', 'Runtime state change queued.');

        Queue::assertPushed(ApplyEnvironmentRuntimeStateJob::class, fn (ApplyEnvironmentRuntimeStateJob $job): bool => $job->environmentId === $environment->id && $job->hibernate === true);
    }

    public function test_web_runtime_validation_precedes_hibernation_entitlement_and_dispatch(): void
    {
        config(['billing.enforce_entitlements' => true]);
        Queue::fake();
        $user = User::factory()->create();
        $project = $user->currentOrganization->projects()->create(['created_by' => $user->id, 'name' => 'Runtime', 'slug' => 'runtime', 'preset' => 'custom']);
        $environment = $project->environments()->create(['name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main']);

        $this->actingAs($user)->patch(route('automation.runtime', $environment), ['state' => 'not-a-state'])
            ->assertSessionHasErrors('state');
        Queue::assertNotPushed(ApplyEnvironmentRuntimeStateJob::class);

        $this->actingAs($user)->patch(route('automation.runtime', $environment), ['state' => 'hibernated'])
            ->assertSessionHasErrors('plan');
        Queue::assertNotPushed(ApplyEnvironmentRuntimeStateJob::class);
    }

    public function test_scheduled_task_denial_precedes_malformed_input_and_writes_nothing(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $owner->currentOrganization->members()->attach($viewer, ['role' => 'viewer']);
        $viewer->update(['current_organization_id' => $owner->current_organization_id]);
        $project = $owner->currentOrganization->projects()->create(['created_by' => $owner->id, 'name' => 'Tasks', 'slug' => 'tasks', 'preset' => 'custom']);
        $environment = $project->environments()->create(['name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main']);

        $this->actingAs($viewer)->post(route('automation.tasks.store', $environment), [])->assertForbidden();

        $this->assertDatabaseCount('scheduled_tasks', 0);
    }

    public function test_free_plan_rejects_scheduled_task_before_persistence(): void
    {
        config(['billing.enforce_entitlements' => true]);
        $user = User::factory()->create();
        $project = $user->currentOrganization->projects()->create(['created_by' => $user->id, 'name' => 'Tasks', 'slug' => 'tasks', 'preset' => 'custom']);
        $environment = $project->environments()->create(['name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main']);

        $this->actingAs($user)->post(route('automation.tasks.store', $environment), [
            'name' => 'Warm cache', 'cron_expression' => '*/5 * * * *', 'timezone' => 'UTC',
            'command' => 'php artisan cache:warm', 'timeout_seconds' => 120,
            'without_overlapping' => '1', 'alert_on_failure' => '1',
        ])->assertSessionHasErrors('plan');

        $this->assertDatabaseCount('scheduled_tasks', 0);
    }

    public function test_owner_can_queue_a_manual_scheduled_task_run(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $project = $user->currentOrganization->projects()->create(['created_by' => $user->id, 'name' => 'Tasks', 'slug' => 'tasks', 'preset' => 'custom']);
        $environment = $project->environments()->create(['name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main']);
        $task = $environment->scheduledTasks()->create([
            'created_by' => $user->id, 'name' => 'Warm cache', 'cron_expression' => '*/5 * * * *', 'timezone' => 'UTC',
            'command' => 'php artisan cache:warm', 'timeout_seconds' => 120,
            'without_overlapping' => true, 'alert_on_failure' => true, 'is_enabled' => true,
        ]);

        $this->actingAs($user)->post(route('automation.tasks.run', $task))
            ->assertRedirect()->assertSessionHas('success', 'Task queued.');

        $run = $task->runs()->sole();
        $this->assertSame('queued', $run->status);
        $this->assertNotNull($task->fresh()->last_queued_at);
        Queue::assertPushed(RunScheduledTaskJob::class, fn (RunScheduledTaskJob $job): bool => $job->runId === $run->id);
    }

    public function test_manual_task_run_preserves_the_non_overlap_guard(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $project = $user->currentOrganization->projects()->create(['created_by' => $user->id, 'name' => 'Tasks', 'slug' => 'tasks', 'preset' => 'custom']);
        $environment = $project->environments()->create(['name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main']);
        $task = $environment->scheduledTasks()->create([
            'created_by' => $user->id, 'name' => 'Warm cache', 'cron_expression' => '*/5 * * * *', 'timezone' => 'UTC',
            'command' => 'php artisan cache:warm', 'timeout_seconds' => 120,
            'without_overlapping' => true, 'alert_on_failure' => true, 'is_enabled' => true,
        ]);
        $existing = $task->runs()->create(['status' => 'running']);

        $this->actingAs($user)->post(route('automation.tasks.run', $task))
            ->assertRedirect()->assertSessionHasErrors('task');

        $this->assertDatabaseCount('scheduled_task_runs', 1);
        $this->assertSame($existing->id, $task->runs()->sole()->id);
        Queue::assertNotPushed(RunScheduledTaskJob::class);
    }

    public function test_manual_task_run_denial_precedes_an_active_run_check(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $owner->currentOrganization->members()->attach($viewer, ['role' => 'viewer']);
        $viewer->update(['current_organization_id' => $owner->current_organization_id]);
        $project = $owner->currentOrganization->projects()->create(['created_by' => $owner->id, 'name' => 'Tasks', 'slug' => 'tasks', 'preset' => 'custom']);
        $environment = $project->environments()->create(['name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main']);
        $task = $environment->scheduledTasks()->create([
            'created_by' => $owner->id, 'name' => 'Warm cache', 'cron_expression' => '*/5 * * * *', 'timezone' => 'UTC',
            'command' => 'php artisan cache:warm', 'timeout_seconds' => 120,
            'without_overlapping' => true, 'alert_on_failure' => true, 'is_enabled' => true,
        ]);
        $task->runs()->create(['status' => 'running']);

        $this->actingAs($viewer)->post(route('automation.tasks.run', $task))->assertForbidden();

        $this->assertDatabaseCount('scheduled_task_runs', 1);
        Queue::assertNotPushed(RunScheduledTaskJob::class);
    }

    public function test_owner_can_delete_automation_schedule_and_task_records(): void
    {
        $user = User::factory()->create();
        $project = $user->currentOrganization->projects()->create(['created_by' => $user->id, 'name' => 'Automation', 'slug' => 'automation', 'preset' => 'custom']);
        $environment = $project->environments()->create(['name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main']);
        $deploymentSchedule = $environment->deploymentSchedules()->create(['created_by' => $user->id, 'name' => 'Deploy', 'cron_expression' => '0 3 * * *', 'timezone' => 'UTC', 'is_enabled' => true]);
        $scalingSchedule = $environment->scalingSchedules()->create(['created_by' => $user->id, 'name' => 'Scale', 'cron_expression' => '0 8 * * *', 'timezone' => 'UTC', 'replicas' => 2, 'is_enabled' => true]);
        $task = $environment->scheduledTasks()->create([
            'created_by' => $user->id, 'name' => 'Task', 'cron_expression' => '0 * * * *', 'timezone' => 'UTC',
            'command' => 'php artisan cache:warm', 'timeout_seconds' => 120,
            'without_overlapping' => true, 'alert_on_failure' => true, 'is_enabled' => true,
        ]);

        $this->actingAs($user)->delete(route('automation.deployment-schedules.destroy', $deploymentSchedule))->assertRedirect()->assertSessionHas('success', 'Deployment schedule deleted.');
        $this->actingAs($user)->delete(route('automation.scaling-schedules.destroy', $scalingSchedule))->assertRedirect()->assertSessionHas('success', 'Scaling schedule deleted.');
        $this->actingAs($user)->delete(route('automation.tasks.destroy', $task))->assertRedirect()->assertSessionHas('success', 'Scheduled task deleted.');

        $this->assertDatabaseCount('deployment_schedules', 0);
        $this->assertDatabaseCount('scaling_schedules', 0);
        $this->assertDatabaseCount('scheduled_tasks', 0);
    }

    public function test_viewer_cannot_delete_automation_records(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $owner->currentOrganization->members()->attach($viewer, ['role' => 'viewer']);
        $viewer->update(['current_organization_id' => $owner->current_organization_id]);
        $project = $owner->currentOrganization->projects()->create(['created_by' => $owner->id, 'name' => 'Automation', 'slug' => 'automation', 'preset' => 'custom']);
        $environment = $project->environments()->create(['name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main']);
        $deploymentSchedule = $environment->deploymentSchedules()->create(['created_by' => $owner->id, 'name' => 'Deploy', 'cron_expression' => '0 3 * * *', 'timezone' => 'UTC', 'is_enabled' => true]);
        $scalingSchedule = $environment->scalingSchedules()->create(['created_by' => $owner->id, 'name' => 'Scale', 'cron_expression' => '0 8 * * *', 'timezone' => 'UTC', 'replicas' => 2, 'is_enabled' => true]);
        $task = $environment->scheduledTasks()->create([
            'created_by' => $owner->id, 'name' => 'Task', 'cron_expression' => '0 * * * *', 'timezone' => 'UTC',
            'command' => 'php artisan cache:warm', 'timeout_seconds' => 120,
            'without_overlapping' => true, 'alert_on_failure' => true, 'is_enabled' => true,
        ]);

        $this->actingAs($viewer)->delete(route('automation.deployment-schedules.destroy', $deploymentSchedule))->assertForbidden();
        $this->actingAs($viewer)->delete(route('automation.scaling-schedules.destroy', $scalingSchedule))->assertForbidden();
        $this->actingAs($viewer)->delete(route('automation.tasks.destroy', $task))->assertForbidden();

        $this->assertDatabaseCount('deployment_schedules', 1);
        $this->assertDatabaseCount('scaling_schedules', 1);
        $this->assertDatabaseCount('scheduled_tasks', 1);
    }

    public function test_workflow_yaml_applies_schedules_scaling_and_processes_atomically(): void
    {
        $user = User::factory()->create();
        $project = $user->currentOrganization->projects()->create(['created_by' => $user->id, 'name' => 'Store', 'slug' => 'store', 'preset' => 'laravel']);
        $environment = $project->environments()->create(['name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main']);
        $yaml = <<<'YAML'
        version: 1
        environments:
          production:
            deployment:
              cron: '0 3 * * 1-5'
              timezone: UTC
            scale:
              minimum: 1
              maximum: 4
              desired: 2
              hibernate_after_minutes: 60
            scaling_schedules:
              - name: morning
                cron: '0 8 * * 1-5'
                timezone: UTC
                replicas: 4
            processes:
              emails:
                type: worker
                command: php artisan queue:work --queue=emails
                replicas: 2
        YAML;

        app(WorkflowConfiguration::class)->apply($project, $yaml, $user->id);

        $environment->refresh();
        $this->assertSame(2, $environment->desired_replicas);
        $this->assertSame(60, $environment->hibernate_after_minutes);
        $this->assertSame('0 3 * * 1-5', $environment->deploymentSchedules()->sole()->cron_expression);
        $this->assertSame(4, $environment->scalingSchedules()->sole()->replicas);
        $this->assertSame('emails', $environment->processes()->sole()->name);
        $this->assertNotSame($yaml, DB::table('projects')->where('id', $project->id)->value('workflow_yaml'));
    }

    public function test_api_is_scoped_and_honors_token_abilities(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $project = $owner->currentOrganization->projects()->create(['created_by' => $owner->id, 'name' => 'API App', 'slug' => 'api-app', 'preset' => 'custom']);
        $environment = $project->environments()->create(['name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main', 'minimum_replicas' => 1, 'maximum_replicas' => 3]);
        $outsider = User::factory()->create();
        $foreignProject = $outsider->currentOrganization->projects()->create(['created_by' => $outsider->id, 'name' => 'Private App', 'slug' => 'private', 'preset' => 'custom']);
        $foreignEnvironment = $foreignProject->environments()->create(['name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main', 'minimum_replicas' => 1, 'maximum_replicas' => 3]);
        Sanctum::actingAs($owner, ['read']);

        $this->getJson('/api/v1/projects')->assertOk()->assertJsonFragment(['name' => 'API App'])->assertJsonMissing(['name' => 'Private App']);
        $this->getJson('/api/v1/projects/'.$project->id)->assertOk();
        $this->patchJson('/api/v1/environments/'.$environment->id.'/scale', [])->assertForbidden();
        $this->patchJson('/api/v1/environments/'.$environment->id.'/runtime', [])->assertForbidden();

        Sanctum::actingAs($owner, ['manage']);
        $this->patchJson('/api/v1/environments/'.$foreignEnvironment->id.'/scale', ['replicas' => 2])->assertForbidden();
        Queue::assertNotPushed(ApplyEnvironmentRuntimeStateJob::class);
    }

    public function test_api_scale_and_runtime_preserve_envelopes_and_queue_shared_operations(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $project = $user->currentOrganization->projects()->create(['created_by' => $user->id, 'name' => 'API Runtime', 'slug' => 'api-runtime', 'preset' => 'custom']);
        $environment = $project->environments()->create([
            'name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main',
            'minimum_replicas' => 1, 'maximum_replicas' => 5, 'desired_replicas' => 1,
        ]);
        Sanctum::actingAs($user, ['manage']);

        $this->patchJson('/api/v1/environments/'.$environment->id.'/scale', ['replicas' => 3])
            ->assertStatus(202)
            ->assertJson(['data' => ['desired_replicas' => 3, 'status' => 'queued']]);
        $this->assertSame(3, $environment->fresh()->desired_replicas);

        $this->patchJson('/api/v1/environments/'.$environment->id.'/runtime', ['state' => 'running'])
            ->assertStatus(202)
            ->assertJson(['data' => ['status' => 'queued', 'state' => 'running']]);

        // The job remains unique per environment, so the second queued transition is coalesced.
        Queue::assertPushed(ApplyEnvironmentRuntimeStateJob::class, 1);
        Queue::assertPushed(ApplyEnvironmentRuntimeStateJob::class, fn (ApplyEnvironmentRuntimeStateJob $job): bool => $job->environmentId === $environment->id && $job->hibernate === false);
    }

    public function test_api_can_queue_hibernation_with_the_existing_response_contract(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $project = $user->currentOrganization->projects()->create(['created_by' => $user->id, 'name' => 'API Runtime', 'slug' => 'api-runtime', 'preset' => 'custom']);
        $environment = $project->environments()->create(['name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main']);
        Sanctum::actingAs($user, ['manage']);

        $this->patchJson('/api/v1/environments/'.$environment->id.'/runtime', ['state' => 'hibernated'])
            ->assertStatus(202)
            ->assertJson(['data' => ['status' => 'queued', 'state' => 'hibernated']]);

        Queue::assertPushed(ApplyEnvironmentRuntimeStateJob::class, fn (ApplyEnvironmentRuntimeStateJob $job): bool => $job->environmentId === $environment->id && $job->hibernate === true);
    }

    public function test_api_runtime_validation_precedes_hibernation_entitlement(): void
    {
        config(['billing.enforce_entitlements' => true, 'billing.plans.free.entitlements' => ['api']]);
        Queue::fake();
        $user = User::factory()->create();
        $project = $user->currentOrganization->projects()->create(['created_by' => $user->id, 'name' => 'API Runtime', 'slug' => 'api-runtime', 'preset' => 'custom']);
        $environment = $project->environments()->create(['name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main']);
        Sanctum::actingAs($user, ['manage']);

        $this->patchJson('/api/v1/environments/'.$environment->id.'/runtime', ['state' => 'invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors('state');
        Queue::assertNotPushed(ApplyEnvironmentRuntimeStateJob::class);

        $this->patchJson('/api/v1/environments/'.$environment->id.'/runtime', ['state' => 'hibernated'])
            ->assertUnprocessable()->assertJsonValidationErrors('plan');
        Queue::assertNotPushed(ApplyEnvironmentRuntimeStateJob::class);
    }

    public function test_due_scaling_schedule_is_claimed_once_and_queues_runtime_change(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $project = $user->currentOrganization->projects()->create(['created_by' => $user->id, 'name' => 'Scale', 'slug' => 'scale', 'preset' => 'custom']);
        $environment = $project->environments()->create(['name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main', 'minimum_replicas' => 1, 'maximum_replicas' => 5]);
        $schedule = $environment->scalingSchedules()->create(['created_by' => $user->id, 'name' => 'Now', 'replicas' => 3, 'cron_expression' => '* * * * *', 'timezone' => 'UTC', 'is_enabled' => true]);

        $this->artisan('buildpusher:scaling:scheduled')->assertSuccessful();
        $this->artisan('buildpusher:scaling:scheduled')->assertSuccessful();

        $this->assertSame(3, $environment->fresh()->desired_replicas);
        $this->assertNotNull($schedule->fresh()->last_run_at);
        Queue::assertPushed(ApplyEnvironmentRuntimeStateJob::class, 1);
    }

    public function test_automation_screen_is_compact_and_available_to_authenticated_users(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('automation.index'))->assertOk()->assertSee('Application workflows')->assertSee('Personal access tokens');
    }

    public function test_owner_can_create_expiring_token_and_rotate_it(): void
    {
        $user = User::factory()->create();

        $create = $this->actingAs($user)->post(route('automation.tokens.store'), [
            'name' => 'Release bot',
            'abilities' => ['read', 'deploy'],
            'expires_in_days' => 90,
        ]);

        $create->assertRedirect()->assertSessionHas('plainTextToken');
        $token = $user->tokens()->sole();
        $this->assertTrue($token->expires_at->isBetween(now()->addDays(89), now()->addDays(91)));
        $oldHash = $token->token;

        $rotate = $this->post(route('automation.tokens.rotate', $token));

        $rotate->assertRedirect()->assertSessionHas('plainTextToken');
        $replacement = $user->tokens()->sole();
        $this->assertNotSame($token->id, $replacement->id);
        $this->assertNotSame($oldHash, $replacement->token);
        $this->assertSame(['read', 'deploy'], $replacement->abilities);
        $this->assertTrue($replacement->expires_at->isBetween(now()->addMonths(11), now()->addMonths(13)));
    }

    public function test_api_reference_and_openapi_document_are_public(): void
    {
        $this->get(route('api-docs'))->assertOk()->assertSee('Control plane API')->assertSee('/openapi.json');
        $this->get('/openapi.json')->assertOk()->assertHeader('Content-Type', 'application/json');
        $document = json_decode(file_get_contents(public_path('openapi.json')), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertArrayHasKey('/environments/{environment}/deploy', $document['paths']);
        $this->assertArrayHasKey('/deployments/{build}/promote', $document['paths']);
    }

    public function test_hibernated_environment_wakes_after_a_real_incoming_request(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $provider = $user->providers()->create(['name' => 'Cloud', 'description' => 'Runtime provider', 'provider' => Provider::TYPE_DIGITALOCEAN, 'token' => 'token']);
        $server = $user->servers()->create(['name' => 'Runtime', 'provider_id' => $provider->id, 'public_ip' => '192.0.2.10', 'ssh_private_key' => 'key', 'provisioning_status' => Server::STATUS_ACTIVE]);
        $website = $user->websites()->create(['name' => 'App', 'description' => 'Runtime app', 'server_id' => $server->id, 'url' => 'app.example.test', 'environment' => '', 'provisioning_status' => Website::STATUS_ACTIVE]);
        $project = $user->currentOrganization->projects()->create(['created_by' => $user->id, 'name' => 'Wake', 'slug' => 'wake', 'preset' => 'custom']);
        $environment = $project->environments()->create(['name' => 'Staging', 'slug' => 'staging', 'type' => 'staging', 'branch' => 'main', 'website_id' => $website->id, 'server_id' => $server->id, 'hibernated_at' => now()->subMinute()]);

        $process = new Process(['printf', (string) now()->getTimestamp()]);
        $process->run();
        $ssh = Mockery::mock(ManagedSsh::class);
        $ssh->shouldReceive('execute')->once()->andReturn($process);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldReceive('server')->once()->withArgs(fn (Server $value): bool => $value->is($server))->andReturnSelf();
        $runner->shouldReceive('create')->once()->andReturn($ssh);

        (new WakeHibernatedEnvironmentJob($environment->id))->handle($runner, app(Entitlements::class));

        Queue::assertPushed(ApplyEnvironmentRuntimeStateJob::class, fn (ApplyEnvironmentRuntimeStateJob $job): bool => $job->environmentId === $environment->id && $job->hibernate === false);
    }
}
