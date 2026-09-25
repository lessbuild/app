<?php

namespace Tests\Feature;

use App\Modules\Deployer\Jobs\ApplyLoadBalancerJob;
use App\Modules\Deployer\Jobs\RemoveLoadBalancerJob;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\Provider;
use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LoadBalancerOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.enforce_entitlements' => false, 'billing.enforce_limits' => false]);
        Cache::flush();
    }

    public function test_manager_can_create_manage_and_remove_a_load_balancer(): void
    {
        [$owner, $environment, $loadBalancerServer, $nodeServer] = $this->infrastructure();
        Queue::fake();

        $this->actingAs($owner)->post(route('load-balancers.store'), [
            'environment_id' => $environment->id,
            'server_id' => $loadBalancerServer->id,
            'hostname' => 'edge.example.com',
            'health_path' => '/health',
        ])->assertSessionHas('success', 'Load balancer created. Add at least two application nodes.');
        $loadBalancer = $owner->currentOrganization->loadBalancers()->sole();
        $loadBalancer->update(['status' => 'active', 'last_error' => 'previous_apply_error']);

        $this->actingAs($owner)->post(route('load-balancers.nodes.store', $loadBalancer), [
            'server_id' => $nodeServer->id, 'upstream_port' => 8080, 'weight' => 2,
        ])->assertSessionHas('success', 'Application node added and configuration queued.');
        $this->assertDatabaseHas('load_balancers', ['id' => $loadBalancer->id, 'status' => 'pending', 'last_error' => null]);
        $node = $loadBalancer->nodes()->sole();
        Queue::assertPushed(ApplyLoadBalancerJob::class, fn (ApplyLoadBalancerJob $job): bool => $job->loadBalancerId === $loadBalancer->id);

        $this->actingAs($owner)->post(route('load-balancers.apply', $loadBalancer))
            ->assertSessionHas('success', 'Load-balancer configuration queued.');
        $this->actingAs($owner)->delete(route('load-balancers.nodes.destroy', $node))
            ->assertSessionHas('success', 'Node removed.');
        $this->assertDatabaseMissing('load_balancer_nodes', ['id' => $node->id]);

        $this->actingAs($owner)->delete(route('load-balancers.destroy', $loadBalancer))
            ->assertSessionHas('success', 'Load-balancer removal queued. The route will disappear after remote cleanup succeeds. Remove its DNS record separately if it is no longer used.');
        $this->assertDatabaseHas('load_balancers', ['id' => $loadBalancer->id, 'status' => 'removing', 'last_error' => null]);
        Queue::assertPushed(RemoveLoadBalancerJob::class, fn (RemoveLoadBalancerJob $job): bool => $job->serverId === $loadBalancerServer->id && $job->loadBalancerId === $loadBalancer->id);
    }

    public function test_load_balancer_workflows_prioritize_creation_and_incomplete_nodes(): void
    {
        [$owner, $environment, $loadBalancerServer, $nodeServer] = $this->infrastructure();

        $emptyContent = $this->actingAs($owner)
            ->get(route('load-balancers.index'))
            ->assertSuccessful()
            ->getContent();

        $this->assertStringContainsString('data-modal-trigger="load-balancer-create"', $emptyContent);
        $this->assertDoesNotMatchRegularExpression(
            '/<dialog(?=[^>]*id="load-balancer-create")(?=[^>]*\sopen(?:\s|>))[^>]*>/',
            $emptyContent,
        );

        $this->assertMatchesRegularExpression(
            '/<dialog(?=[^>]*id="load-balancer-create")(?=[^>]*\sopen(?:\s|>))[^>]*>/',
            $this->actingAs($owner)
                ->get(route('load-balancers.index', ['dialog' => 'create-route']))
                ->assertSuccessful()
                ->getContent(),
        );

        $loadBalancer = $owner->currentOrganization->loadBalancers()->create([
            'environment_id' => $environment->id,
            'server_id' => $loadBalancerServer->id,
            'hostname' => 'edge.example.com',
            'health_path' => '/health',
            'status' => 'active',
            'created_by' => $owner->id,
        ]);

        $initialContent = $this->actingAs($owner)
            ->get(route('load-balancers.index'))
            ->assertSuccessful()
            ->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '/<dialog(?=[^>]*id="load-balancer-create")(?=[^>]*\sopen(?:\s|>))[^>]*>/',
            $initialContent,
        );
        $this->assertStringContainsString('data-modal-trigger="load-balancer-node-'.$loadBalancer->id.'"', $initialContent);
        $this->assertStringContainsString('data-modal-trigger="load-balancer-removal-'.$loadBalancer->id.'"', $initialContent);
        $this->assertStringContainsString('Deployer removes the remote routing configuration first.', $initialContent);
        $this->assertMatchesRegularExpression('/<details id="load-balancer-nodes-'.$loadBalancer->id.'"[^>]*\bopen\b[^>]*>/', $initialContent);

        $nodeDialogUrl = route('load-balancers.index', [
            'dialog' => 'add-node',
            'load_balancer_id' => $loadBalancer->id,
        ]);
        $this->assertMatchesRegularExpression(
            '/<dialog(?=[^>]*id="load-balancer-node-'.$loadBalancer->id.'")(?=[^>]*\sopen(?:\s|>))[^>]*>/',
            $this->actingAs($owner)->get($nodeDialogUrl)->assertSuccessful()->getContent(),
        );

        $this->from(route('load-balancers.index'))
            ->followingRedirects()
            ->actingAs($owner)
            ->post(route('load-balancers.store'), [
                '_load_balancer_form' => 'create',
                'environment_id' => $environment->id,
                'server_id' => $loadBalancerServer->id,
                'hostname' => '',
                'health_path' => '/health',
            ])
            ->assertSuccessful()
            ->assertSee('The hostname field is required.');

        $nodeErrorPage = $this->from($nodeDialogUrl)
            ->followingRedirects()
            ->actingAs($owner)
            ->post(route('load-balancers.nodes.store', $loadBalancer), [
                '_load_balancer_id' => $loadBalancer->id,
                'server_id' => '',
                'upstream_port' => 0,
                'weight' => 0,
            ])
            ->assertSuccessful();

        $this->assertMatchesRegularExpression(
            '/<dialog(?=[^>]*id="load-balancer-node-'.$loadBalancer->id.'")(?=[^>]*\sopen(?:\s|>))[^>]*>/',
            $nodeErrorPage->getContent(),
        );
        $nodeErrorPage->assertSee('The server id field is required.');

        $nodeServerTwo = $owner->servers()->create([
            'provider_id' => $nodeServer->provider_id,
            'name' => 'Node two',
            'public_ip' => '203.0.113.40',
            'ssh_private_key' => 'key',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $loadBalancer->nodes()->create([
            'server_id' => $nodeServer->id,
            'upstream_port' => 8080,
            'weight' => 1,
            'health_status' => 'healthy',
        ]);
        $loadBalancer->nodes()->create([
            'server_id' => $nodeServerTwo->id,
            'upstream_port' => 8080,
            'weight' => 1,
            'health_status' => 'healthy',
        ]);

        $completeContent = $this->actingAs($owner)
            ->get(route('load-balancers.index'))
            ->assertSuccessful()
            ->getContent();

        $this->assertDoesNotMatchRegularExpression('/<details id="load-balancer-nodes-'.$loadBalancer->id.'"[^>]*\bopen\b[^>]*>/', $completeContent);
    }

    public function test_dedicated_server_and_self_routing_rules_reject_writes(): void
    {
        [$owner, $environment, $loadBalancerServer] = $this->infrastructure();
        Queue::fake();

        $this->actingAs($owner)->post(route('load-balancers.store'), [
            'environment_id' => $environment->id,
            'server_id' => $environment->server_id,
            'hostname' => 'same-server.example.com',
            'health_path' => '/health',
        ])->assertStatus(422);

        $loadBalancer = $owner->currentOrganization->loadBalancers()->create([
            'environment_id' => $environment->id,
            'server_id' => $loadBalancerServer->id,
            'hostname' => 'edge.example.com',
            'health_path' => '/health',
            'created_by' => $owner->id,
        ]);
        $this->actingAs($owner)->post(route('load-balancers.nodes.store', $loadBalancer), [
            'server_id' => $loadBalancerServer->id, 'upstream_port' => 8080, 'weight' => 1,
        ])->assertStatus(422);

        $this->assertDatabaseCount('load_balancer_nodes', 0);
        Queue::assertNothingPushed();
    }

    public function test_non_manager_cannot_create_or_modify_load_balancers(): void
    {
        [$owner, $environment, $loadBalancerServer] = $this->infrastructure();
        $viewer = User::factory()->create(['current_organization_id' => $owner->current_organization_id]);
        $owner->currentOrganization->members()->attach($viewer->id, ['role' => 'viewer']);
        $loadBalancer = $owner->currentOrganization->loadBalancers()->create([
            'environment_id' => $environment->id,
            'server_id' => $loadBalancerServer->id,
            'hostname' => 'edge.example.com',
            'health_path' => '/health',
            'created_by' => $owner->id,
        ]);
        Queue::fake();

        $this->actingAs($viewer)->post(route('load-balancers.store'), [
            'environment_id' => 'invalid', 'server_id' => 'invalid', 'hostname' => 'bad', 'health_path' => 'bad',
        ])->assertForbidden();
        $this->actingAs($viewer)->post(route('load-balancers.nodes.store', $loadBalancer), [
            'server_id' => 'invalid', 'upstream_port' => 0, 'weight' => 0,
        ])->assertForbidden();
        $this->actingAs($viewer)->delete(route('load-balancers.destroy', $loadBalancer))->assertForbidden();

        $this->assertDatabaseCount('load_balancers', 1);
        $this->assertDatabaseCount('load_balancer_nodes', 0);
        Queue::assertNothingPushed();
    }

    public function test_manager_can_retry_a_failed_remote_load_balancer_removal(): void
    {
        [$owner, $environment, $loadBalancerServer] = $this->infrastructure();
        $loadBalancer = $owner->currentOrganization->loadBalancers()->create([
            'environment_id' => $environment->id,
            'server_id' => $loadBalancerServer->id,
            'hostname' => 'edge.example.com',
            'health_path' => '/health',
            'status' => 'removal_failed',
            'last_error' => 'private_ssh_failure',
            'created_by' => $owner->id,
        ]);
        Queue::fake();

        $this->actingAs($owner)->delete(route('load-balancers.destroy', $loadBalancer))
            ->assertSessionHas('success', 'Load-balancer removal queued. The route will disappear after remote cleanup succeeds. Remove its DNS record separately if it is no longer used.');

        $this->assertDatabaseHas('load_balancers', [
            'id' => $loadBalancer->id,
            'status' => 'removing',
            'last_error' => null,
        ]);
        Queue::assertPushed(RemoveLoadBalancerJob::class, fn (RemoveLoadBalancerJob $job): bool => $job->serverId === $loadBalancerServer->id && $job->loadBalancerId === $loadBalancer->id);
    }

    public function test_apply_and_node_changes_are_rejected_during_load_balancer_removal(): void
    {
        [$owner, $environment, $loadBalancerServer, $nodeServer] = $this->infrastructure();
        $loadBalancer = $owner->currentOrganization->loadBalancers()->create([
            'environment_id' => $environment->id,
            'server_id' => $loadBalancerServer->id,
            'hostname' => 'edge.example.com',
            'health_path' => '/health',
            'status' => 'removing',
            'created_by' => $owner->id,
        ]);
        $node = $loadBalancer->nodes()->create([
            'server_id' => $nodeServer->id,
            'upstream_port' => 8080,
            'weight' => 1,
        ]);
        Queue::fake();

        $this->actingAs($owner)->post(route('load-balancers.apply', $loadBalancer))->assertConflict();
        $this->actingAs($owner)->post(route('load-balancers.nodes.store', $loadBalancer), [
            'server_id' => $nodeServer->id,
            'upstream_port' => 8081,
            'weight' => 1,
        ])->assertConflict();
        $this->actingAs($owner)->delete(route('load-balancers.nodes.destroy', $node))->assertConflict();

        $this->assertDatabaseHas('load_balancers', ['id' => $loadBalancer->id, 'status' => 'removing']);
        $this->assertDatabaseHas('load_balancer_nodes', ['id' => $node->id]);
        Queue::assertNothingPushed();
    }

    public function test_free_workspace_cannot_queue_high_availability_changes(): void
    {
        [$owner, $environment, $loadBalancerServer] = $this->infrastructure();
        config(['billing.enforce_entitlements' => true]);
        Queue::fake();

        $this->actingAs($owner)->post(route('load-balancers.store'), [
            'environment_id' => $environment->id,
            'server_id' => $loadBalancerServer->id,
            'hostname' => 'edge.example.com',
            'health_path' => '/health',
        ])->assertSessionHasErrors('plan');

        $this->assertDatabaseCount('load_balancers', 0);
        Queue::assertNothingPushed();
    }

    /** @return array{User, Environment, Server, Server} */
    private function infrastructure(): array
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create([
            'name' => 'Cloud', 'provider' => Provider::TYPE_DIGITALOCEAN,
            'token' => 'token', 'description' => 'Infrastructure',
        ]);
        $applicationServer = $owner->servers()->create([
            'provider_id' => $provider->id, 'name' => 'Application', 'public_ip' => '203.0.113.10',
            'ssh_private_key' => 'key', 'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $loadBalancerServer = $owner->servers()->create([
            'provider_id' => $provider->id, 'name' => 'Edge', 'public_ip' => '203.0.113.20',
            'ssh_private_key' => 'key', 'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $nodeServer = $owner->servers()->create([
            'provider_id' => $provider->id, 'name' => 'Node', 'public_ip' => '203.0.113.30',
            'ssh_private_key' => 'key', 'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $owner->websites()->create([
            'server_id' => $applicationServer->id, 'name' => 'Application', 'url' => 'app.example.com',
            'description' => 'Application', 'environment' => '', 'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $project = $owner->currentOrganization->projects()->create([
            'created_by' => $owner->id, 'name' => 'Application', 'slug' => 'application-'.str()->random(6),
        ]);
        $environment = $project->environments()->create([
            'name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main',
            'server_id' => $applicationServer->id, 'website_id' => $website->id,
        ]);

        return [$owner, $environment, $loadBalancerServer, $nodeServer];
    }
}
