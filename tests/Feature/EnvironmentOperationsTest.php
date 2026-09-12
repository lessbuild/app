<?php

namespace Tests\Feature;

use App\Models\Environment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnvironmentOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.enforce_entitlements' => true]);
    }

    public function test_worker_and_resource_entitlements_are_checked_before_invalid_input(): void
    {
        $user = User::factory()->create();
        $environment = $this->environment($user);

        $this->actingAs($user)->post(route('environments.processes.store', $environment), [])
            ->assertSessionHasErrors('plan');
        $this->actingAs($user)->post(route('environments.resources.store', $environment), [])
            ->assertSessionHasErrors('plan');

        $this->assertDatabaseCount('environment_processes', 0);
        $this->assertDatabaseCount('environment_resources', 0);
    }

    public function test_process_defaults_and_scheduler_replica_normalization_are_preserved(): void
    {
        config(['billing.enforce_entitlements' => false]);
        $user = User::factory()->create();
        $environment = $this->environment($user);

        $this->actingAs($user)->post(route('environments.processes.store', $environment), [
            'name' => 'scheduler',
            'type' => 'scheduler',
            'command' => 'php artisan schedule:work',
            'replicas' => 20,
            'is_enabled' => '1',
        ])->assertRedirect();

        $process = $environment->processes()->sole();
        $this->assertSame(1, $process->replicas);
        $this->assertSame('always', $process->restart_policy);
        $this->assertSame(5, $process->restart_delay_seconds);
    }

    public function test_resource_action_preserves_validation_failures_without_writing(): void
    {
        config(['billing.enforce_entitlements' => false]);
        $user = User::factory()->create();
        $environment = $this->environment($user);

        $this->actingAs($user)->post(route('environments.resources.store', $environment), [
            'name' => 'storage',
            'type' => 'object_storage',
            'is_managed' => '1',
            'variables' => 'AWS_SECRET_ACCESS_KEY=private-value',
        ])->assertSessionHasErrors('type')->assertSessionHasInput('variables', 'AWS_SECRET_ACCESS_KEY=private-value');

        $this->assertDatabaseCount('environment_resources', 0);
    }

    private function environment(User $user): Environment
    {
        $project = $user->currentOrganization->projects()->create([
            'created_by' => $user->id,
            'name' => 'Application',
            'slug' => 'application',
        ]);

        return $project->environments()->create([
            'name' => 'Staging',
            'slug' => 'staging',
            'type' => 'staging',
            'branch' => 'main',
        ]);
    }
}
