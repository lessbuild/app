<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Enums\AccountRole;
use App\Enums\ProviderType;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\User;
use App\Models\Website;
use App\Services\Infrastructure\ServerPricing;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class InfrastructureCostsTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private Provider $provider;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        $this->project = Project::factory()->withServices(['infrastructure'])->create();
        $this->owner = $this->ownerOf($this->project);
        $this->onTier($this->project, 'deploy', 'pro');
        $this->provider = Provider::factory()->create(['account_id' => $this->project->account_id]);
        $this->base = "/projects/{$this->project->id}/infrastructure/costs";
        $this->withoutMiddleware(RequirePassword::class);
    }

    public function test_costs_come_from_the_provider_catalog_with_idle_servers_and_projects(): void
    {
        $busy = Server::factory()->create(['provider_id' => $this->provider->id, 'name' => 'busy', 'size' => 's-2vcpu-4gb']);
        Server::factory()->create(['provider_id' => $this->provider->id, 'name' => 'idle', 'size' => 's-1vcpu-1gb']);
        $unknown = Server::factory()->create(['provider_id' => $this->provider->id, 'name' => 'odd', 'size' => 'gone']);
        $environment = $this->project->environments()->where('slug', 'production')->firstOrFail();
        Website::factory()->create(['server_id' => $busy->id, 'environment_id' => $environment->id]);
        foreach (range(1, 6) as $minutes) {
            $this->metric($busy, 55, $minutes * 5);
        }
        $this->cloud->sizes = [['slug' => 's-1vcpu-1gb', 'price_monthly' => 6], ['slug' => 's-2vcpu-4gb', 'price_monthly' => 24]];

        $this->command('servers:sync-costs')->expectsOutput('Priced 2 servers.');
        $this->assertSame([24.0, 'USD', 'provider'], [$this->reload($busy)->monthly_cost, $this->reload($busy)->monthly_cost_currency, $this->reload($busy)->monthly_cost_source]);
        $this->assertNull($this->reload($unknown)->monthly_cost);

        $this->actingAs($this->owner)->get($this->base)->assertOk()
            ->assertSee('$30.00')->assertSee('1 server has no known price')->assertSee('55%')->assertSee($this->project->name)
            ->assertSee('Idle')->assertSee('No project');

        $this->actingAs($this->owner)->put("{$this->base}/budget", ['monthly_infrastructure_budget' => '25'])->assertRedirect();
        $this->actingAs($this->owner)->get($this->base)->assertSee('Over by $5.00');
        $this->assertSame(25.0, $this->project->account->refresh()->monthly_infrastructure_budget);
    }

    public function test_hetzner_prices_follow_the_location_in_euros(): void
    {
        $sizes = [['name' => 'cx22', 'prices' => [['location' => 'fsn1', 'price_monthly' => ['gross' => '4.5900']], ['location' => 'ash', 'price_monthly' => ['gross' => '5.4900']]]]];
        $pricing = app(ServerPricing::class);
        $this->assertSame(5.49, $pricing->price(ProviderType::Hetzner, $sizes, 'cx22', 'ash'));
        $this->assertSame(4.59, $pricing->price(ProviderType::Hetzner, $sizes, 'cx22', 'nbg1'));
        $this->assertSame(10.0, $pricing->price(ProviderType::Vultr, [['id' => 'vc2-1c-2gb', 'monthly_cost' => 10]], 'vc2-1c-2gb', 'ewr'));
        $this->assertNull($pricing->price(ProviderType::DigitalOcean, [], 's-1vcpu-1gb', 'fra1'));
    }

    public function test_imported_servers_take_an_entered_cost_and_budgets_need_the_plan(): void
    {
        $imported = Server::factory()->create(['provider_id' => null, 'account_id' => $this->project->account_id, 'name' => 'legacy', 'size' => null]);
        $cloud = Server::factory()->create(['provider_id' => $this->provider->id]);
        $this->actingAs($this->owner)->put("{$this->base}/servers/{$imported->id}", ['monthly_cost' => '12.5', 'monthly_cost_currency' => 'EUR'])->assertRedirect();
        $this->assertSame([12.5, 'EUR', 'manual'], [$this->reload($imported)->monthly_cost, $this->reload($imported)->monthly_cost_currency, $this->reload($imported)->monthly_cost_source]);
        $this->actingAs($this->owner)->put("{$this->base}/servers/{$cloud->id}", ['monthly_cost' => '1', 'monthly_cost_currency' => 'USD'])->assertSessionHasErrors('monthly_cost');
        $this->actingAs($this->owner)->get($this->base)->assertSee('€12.50')->assertSee('entered');

        $member = User::factory()->create();
        $this->addMember($this->project, $member, AccountRole::Member);
        $this->actingAs($member)->get($this->base)->assertOk()->assertDontSee('Check prices now')->assertDontSee('Save budget');
        $this->actingAs($member)->put("{$this->base}/budget", ['monthly_infrastructure_budget' => '5'])->assertForbidden();

        $this->onTier($this->project, 'deploy', 'free');
        $this->actingAs($this->owner)->get($this->base)->assertSee('Budgets come with the Pro Deploy plan');
        $this->actingAs($this->owner)->put("{$this->base}/budget", ['monthly_infrastructure_budget' => '5'])->assertForbidden();
    }

    private function metric(Server $server, int $cpu, int $minutesAgo): void
    {
        $metric = new ServerMetric;
        $metric->forceFill(['server_id' => $server->id, 'load_1m' => 0.5, 'load_5m' => 0.5, 'load_15m' => 0.5, 'cpu_percent' => $cpu, 'memory_percent' => 40, 'disk_percent' => 30,
            'network_rx_bytes' => 0, 'network_tx_bytes' => 0, 'disk_read_bytes' => 0, 'disk_write_bytes' => 0, 'process_count' => 80, 'uptime_seconds' => 100, 'recorded_at' => now()->subMinutes($minutesAgo)])->save();
    }
}
