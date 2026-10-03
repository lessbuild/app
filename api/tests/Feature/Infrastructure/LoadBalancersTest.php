<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Enums\AccountRole;
use App\Enums\ServerType;
use App\Models\LoadBalancer;
use App\Models\LoadBalancerNode;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class LoadBalancersTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private Server $proxy;

    private Server $app1;

    private Server $app2;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        $this->project = Project::factory()->withServices(['infrastructure'])->create();
        $this->owner = $this->ownerOf($this->project);
        $this->onTier($this->project, 'deploy', 'business');
        $provider = Provider::factory()->create(['account_id' => $this->project->account_id]);
        $this->proxy = Server::factory()->create(['provider_id' => $provider->id, 'type' => ServerType::LoadBalancer, 'public_ip' => '203.0.113.1']);
        $this->app1 = Server::factory()->create(['provider_id' => $provider->id, 'public_ip' => '203.0.113.10']);
        $this->app2 = Server::factory()->create(['provider_id' => $provider->id, 'public_ip' => '203.0.113.11']);
        $this->base = "/projects/{$this->project->id}/infrastructure/load-balancers";
        $this->withoutMiddleware(RequirePassword::class);
    }

    public function test_a_load_balancer_writes_a_caddy_site_that_follows_its_nodes(): void
    {
        $website = Website::factory()->create(['server_id' => $this->app1->id, 'name' => 'Shop']);
        $this->actingAs($this->owner)->post($this->base, ['server_id' => $this->proxy->id, 'hostname' => 'https://Shop.Example.com/', 'health_path' => '/up', 'website_id' => $website->id])
            ->assertRedirect($this->base)->assertSessionHasNoErrors();

        $balancer = LoadBalancer::query()->sole();
        $this->assertSame(['shop.example.com', 'active', $website->id], [$balancer->hostname, $balancer->status, $balancer->website_id]);
        $this->assertStringContainsString('respond "No healthy application servers" 503', $this->site());

        $this->actingAs($this->owner)->post("{$this->base}/{$balancer->id}/nodes", ['server_id' => $this->app1->id, 'upstream_port' => 80, 'weight' => 2])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post("{$this->base}/{$balancer->id}/nodes", ['server_id' => $this->app2->id, 'upstream_port' => 8080, 'weight' => 1])->assertSessionHasNoErrors();
        $site = $this->site();
        $this->assertStringContainsString('reverse_proxy http://203.0.113.10:80 http://203.0.113.10:80 http://203.0.113.11:8080 {', $site);
        $this->assertStringContainsString('health_uri /up', $site);
        $this->assertSame($this->proxy->id, collect($this->shell->ran)->last()['server'] ?? null);

        $node = LoadBalancerNode::query()->where('server_id', $this->app2->id)->sole();
        $this->actingAs($this->owner)->put("{$this->base}/{$balancer->id}/nodes/{$node->id}", ['upstream_port' => 8080, 'weight' => 1, 'is_enabled' => 0])->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('203.0.113.11', $this->site());
        $this->actingAs($this->owner)->get($this->base)->assertOk()->assertSee('shop.example.com')->assertSee('out of rotation')->assertSee('Shop');

        $this->actingAs($this->owner)->post("{$this->base}/{$balancer->id}/nodes", ['server_id' => $this->proxy->id, 'upstream_port' => 80, 'weight' => 1])->assertSessionHasErrors('server_id');
        $this->actingAs($this->owner)->post("{$this->base}/{$balancer->id}/nodes", ['server_id' => $this->app1->id, 'upstream_port' => 80, 'weight' => 1])->assertSessionHasErrors('server_id');
        $this->actingAs($this->owner)->post("{$this->base}/{$balancer->id}/nodes", ['server_id' => Server::factory()->create()->id, 'upstream_port' => 80, 'weight' => 1])->assertSessionHasErrors('server_id');

        $this->actingAs($this->owner)->delete("{$this->base}/{$balancer->id}/nodes/{$node->id}")->assertRedirect();
        $this->assertModelMissing($node);
    }

    public function test_failures_can_be_retried_and_deleting_removes_the_site_first(): void
    {
        $this->shell->reply('', 1, 'Error: adapting config using caddyfile');
        $this->actingAs($this->owner)->post($this->base, ['server_id' => $this->proxy->id, 'hostname' => 'shop.example.com', 'health_path' => '/up']);
        $balancer = LoadBalancer::query()->sole();
        $this->assertSame(['failed', 'Error: adapting config using caddyfile'], [$balancer->status, $balancer->last_error]);

        $this->actingAs($this->owner)->post("{$this->base}/{$balancer->id}/retry")->assertRedirect();
        $this->assertSame('active', $this->reload($balancer)->status);

        $this->shell->reply('', 1, 'ssh: connect to host timed out');
        $this->actingAs($this->owner)->delete("{$this->base}/{$balancer->id}")->assertRedirect();
        $this->assertSame('removal_failed', $this->reload($balancer)->status);
        $this->actingAs($this->owner)->put("{$this->base}/{$balancer->id}", ['hostname' => 'shop.example.com', 'health_path' => '/'])->assertStatus(409);

        $this->actingAs($this->owner)->post("{$this->base}/{$balancer->id}/retry")->assertRedirect();
        $this->assertModelMissing($balancer);
        $this->assertStringContainsString("rm -f -- '/etc/caddy/websites/ha-{$balancer->id}.conf'", (string) (collect($this->shell->ran)->last()['command'] ?? ''));
    }

    public function test_load_balancers_need_a_caddy_server_admin_rights_and_the_plan(): void
    {
        $cache = Server::factory()->create(['provider_id' => $this->proxy->provider_id, 'type' => ServerType::Cache]);
        $this->actingAs($this->owner)->post($this->base, ['server_id' => $cache->id, 'hostname' => 'a.example.com', 'health_path' => '/'])->assertSessionHasErrors('server_id');
        $this->actingAs($this->owner)->post($this->base, ['server_id' => $this->proxy->id, 'hostname' => 'a.example.com', 'health_path' => 'up'])->assertSessionHasErrors('health_path');
        $website = Website::factory()->create(['server_id' => $this->app1->id]);
        $this->actingAs($this->owner)->post($this->base, ['server_id' => $this->app1->id, 'hostname' => 'a.example.com', 'health_path' => '/', 'website_id' => $website->id])->assertSessionHasErrors('server_id');

        $member = User::factory()->create();
        $this->addMember($this->project, $member, AccountRole::Member);
        $this->actingAs($member)->get($this->base)->assertOk()->assertDontSee('Add a load balancer');
        $this->actingAs($member)->post($this->base, ['server_id' => $this->proxy->id, 'hostname' => 'a.example.com', 'health_path' => '/'])->assertForbidden();

        $this->onTier($this->project, 'deploy', 'pro');
        $this->actingAs($this->owner)->get($this->base)->assertSee('Load balancers come with the Business Deploy plan');
        $this->actingAs($this->owner)->post($this->base, ['server_id' => $this->proxy->id, 'hostname' => 'a.example.com', 'health_path' => '/'])->assertForbidden();
        $this->assertSame(0, LoadBalancer::query()->count());
    }

    /** The Caddy site in the last command sent to the proxy. */
    private function site(): string
    {
        preg_match("/printf '%s' '([A-Za-z0-9+\\/=]+)'/", (string) (collect($this->shell->ran)->last()['command'] ?? ''), $match);

        return (string) base64_decode($match[1] ?? '', true);
    }
}
