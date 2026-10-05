<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Enums\AccountRole;
use App\Enums\ProviderType;
use App\Models\Project;
use App\Models\Provider;
use App\Models\ProviderBill;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\User;
use App\Models\Website;
use App\Services\Infrastructure\ServerPricing;
use App\Services\Infrastructure\ServerRightsizing;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
        $this->base = "/api/app/projects/{$this->project->id}/infrastructure/costs";
        $this->withoutMiddleware(RequirePassword::class);
    }

    /**
     * Costs come from the provider catalog with idle servers and projects.
     */
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

        $this->actingAs($this->owner)->getJson($this->base)->assertOk()
            ->assertJsonPath('totals.USD', fn ($total): bool => (float) $total === 30.0)->assertJsonPath('unknown', 1)->assertSee($this->project->name)
            ->assertJsonPath('rows', fn (array $rows): bool => collect($rows)->contains(fn (array $row): bool => $row['averageCpu'] === 55 || $row['averageCpu'] === 55.0)
                && collect($rows)->contains(fn (array $row): bool => $row['idle']) && collect($rows)->contains(fn (array $row): bool => $row['attribution'] === 'unallocated'));

        $this->actingAs($this->owner)->putJson("{$this->base}/budget", ['monthly_infrastructure_budget' => '25'])->assertSuccessful();
        $this->actingAs($this->owner)->getJson($this->base)->assertJsonPath('budget', fn ($budget): bool => (float) $budget === 25.0);
        $this->assertSame(25.0, $this->project->account->refresh()->monthly_infrastructure_budget);
    }

    /**
     * Check the daily import reads DigitalOcean's invoices and usage so far, the Costs page compares last month's
     * invoice with the list-price estimate, and a token without billing access is explained rather than failing.
     *
     * @return void
     */
    public function test_actual_bills_are_imported_and_compared_with_the_estimate(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 15));
        Server::factory()->create(['provider_id' => $this->provider->id, 'size' => 's-2vcpu-4gb', 'monthly_cost' => 24, 'monthly_cost_currency' => 'USD']);
        $refused = Provider::factory()->type(ProviderType::Vultr)->create(['account_id' => $this->project->account_id, 'name' => 'Vultr main']);
        Http::fake([
            'api.digitalocean.com/v2/customers/my/invoices*' => Http::response(['invoices' => [['invoice_period' => '2026-09', 'amount' => '31.40'], ['invoice_period' => '2026-08', 'amount' => '24.00']]]),
            'api.digitalocean.com/v2/customers/my/balance' => Http::response(['month_to_date_usage' => '12.10']),
            'api.vultr.com/*' => Http::response(['error' => 'Unauthorized'], 403),
        ]);

        $this->command('providers:sync-bills')->expectsOutput('Stored 3 months of cloud bills.');

        $this->assertSame([31.4, true], [ProviderBill::query()->where('period', '2026-09')->sole()->amount, ProviderBill::query()->where('period', '2026-09')->sole()->final]);
        $this->assertFalse(ProviderBill::query()->where('period', '2026-10')->sole()->final);
        $this->assertStringContainsString('billing read access', (string) $refused->refresh()->billing_error);
        $this->actingAs($this->owner)->getJson($this->base)->assertOk()
            ->assertJsonPath('bills', fn (array $bills): bool => collect($bills)->contains(fn (array $bill): bool => ($bill['previous']['amount'] ?? null) === 31.4 && round((float) $bill['difference'], 2) === 7.4 && ($bill['current']['amount'] ?? null) === 12.1)
                && collect($bills)->contains(fn (array $bill): bool => str_contains((string) $bill['error'], 'give the credential billing read access')));
    }

    /**
     * Check two weeks of readings suggest a smaller size for a mostly idle server (with the saving) and a bigger one
     * for a server running hot, while a server with too little history or a good fit gets nothing.
     *
     * @return void
     */
    public function test_servers_get_right_sizing_suggestions(): void
    {
        $this->cloud->sizes = [
            ['slug' => 's-1vcpu-1gb', 'vcpus' => 1, 'memory' => 1024, 'price_monthly' => 6],
            ['slug' => 's-2vcpu-4gb', 'vcpus' => 2, 'memory' => 4096, 'price_monthly' => 24],
            ['slug' => 's-4vcpu-8gb', 'vcpus' => 4, 'memory' => 8192, 'price_monthly' => 48],
        ];
        $idle = Server::factory()->create(['provider_id' => $this->provider->id, 'account_id' => $this->project->account_id, 'provisioning_status' => Server::STATUS_ACTIVE, 'name' => 'oversized', 'size' => 's-4vcpu-8gb']);
        $hot = Server::factory()->create(['provider_id' => $this->provider->id, 'account_id' => $this->project->account_id, 'provisioning_status' => Server::STATUS_ACTIVE, 'name' => 'stretched', 'size' => 's-1vcpu-1gb']);
        $fine = Server::factory()->create(['provider_id' => $this->provider->id, 'account_id' => $this->project->account_id, 'provisioning_status' => Server::STATUS_ACTIVE, 'name' => 'fine', 'size' => 's-2vcpu-4gb']);
        $new = Server::factory()->create(['provider_id' => $this->provider->id, 'account_id' => $this->project->account_id, 'provisioning_status' => Server::STATUS_ACTIVE, 'name' => 'new', 'size' => 's-4vcpu-8gb']);
        foreach ([[$idle, 10, 20, 5000], [$hot, 95, 70, 5000], [$fine, 55, 60, 5000], [$new, 5, 5, 100]] as [$server, $cpu, $memory, $count]) {
            foreach (array_chunk(range(1, $count), 500) as $chunk) {
                DB::table('server_metrics')->insert(array_map(fn (int $minute): array => ['server_id' => $server->id, 'load_1m' => 1, 'load_5m' => 1, 'load_15m' => 1, 'cpu_percent' => $cpu, 'memory_percent' => $memory, 'disk_percent' => 30, 'uptime_seconds' => 100, 'recorded_at' => now()->subMinutes($minute)], $chunk));
            }
        }

        /** @var array<string, array{direction: string, suggested: array{id: string}, saving: float|null}> $suggestions */
        $suggestions = collect(app(ServerRightsizing::class)->suggestions(Server::query()->with('provider')->get()))->keyBy(fn (array $row): string => $row['server']->name)->all();
        $this->assertEqualsCanonicalizing(['oversized', 'stretched'], array_keys($suggestions));
        $this->assertSame(['down', 's-2vcpu-4gb', 24.0], [$suggestions['oversized']['direction'], $suggestions['oversized']['suggested']['id'], $suggestions['oversized']['saving']]);
        $this->assertSame(['up', 's-2vcpu-4gb', -18.0], [$suggestions['stretched']['direction'], $suggestions['stretched']['suggested']['id'], $suggestions['stretched']['saving']]);

        $this->actingAs($this->owner)->getJson($this->base)->assertOk()->assertJsonPath('rightsizing', fn (array $rows): bool => collect($rows)->contains(fn (array $row): bool => round((float) $row['saving'], 2) === 24.0 && $row['currency'] === 'USD') && collect($rows)->contains(fn (array $row): bool => round((float) $row['saving'], 2) === -18.0));
    }

    /**
     * Hetzner prices follow the location in euros.
     */
    public function test_hetzner_prices_follow_the_location_in_euros(): void
    {
        $sizes = [['name' => 'cx22', 'prices' => [['location' => 'fsn1', 'price_monthly' => ['gross' => '4.5900']], ['location' => 'ash', 'price_monthly' => ['gross' => '5.4900']]]]];
        $pricing = app(ServerPricing::class);
        $this->assertSame(5.49, $pricing->price(ProviderType::Hetzner, $sizes, 'cx22', 'ash'));
        $this->assertSame(4.59, $pricing->price(ProviderType::Hetzner, $sizes, 'cx22', 'nbg1'));
        $this->assertSame(10.0, $pricing->price(ProviderType::Vultr, [['id' => 'vc2-1c-2gb', 'monthly_cost' => 10]], 'vc2-1c-2gb', 'ewr'));
        $this->assertNull($pricing->price(ProviderType::DigitalOcean, [], 's-1vcpu-1gb', 'fra1'));
    }

    /**
     * Imported servers take an entered cost and budgets need the plan.
     */
    public function test_imported_servers_take_an_entered_cost_and_budgets_need_the_plan(): void
    {
        $imported = Server::factory()->create(['provider_id' => null, 'account_id' => $this->project->account_id, 'name' => 'legacy', 'size' => null]);
        $cloud = Server::factory()->create(['provider_id' => $this->provider->id]);
        $this->actingAs($this->owner)->putJson("{$this->base}/servers/{$imported->id}", ['monthly_cost' => '12.5', 'monthly_cost_currency' => 'EUR'])->assertSuccessful();
        $this->assertSame([12.5, 'EUR', 'manual'], [$this->reload($imported)->monthly_cost, $this->reload($imported)->monthly_cost_currency, $this->reload($imported)->monthly_cost_source]);
        $this->actingAs($this->owner)->putJson("{$this->base}/servers/{$cloud->id}", ['monthly_cost' => '1', 'monthly_cost_currency' => 'USD'])->assertJsonValidationErrors('monthly_cost');
        $this->actingAs($this->owner)->getJson($this->base)->assertJsonPath('rows', fn (array $rows): bool => collect($rows)->contains(fn (array $row): bool => $row['monthly'] === 12.5 && $row['currency'] === 'EUR' && $row['entered']));

        $member = User::factory()->create();
        $this->addMember($this->project, $member, AccountRole::Member);
        $this->actingAs($member)->getJson($this->base)->assertOk()->assertJsonPath('canManage', false)->assertJsonPath('canBudget', false);
        $this->actingAs($member)->putJson("{$this->base}/budget", ['monthly_infrastructure_budget' => '5'])->assertForbidden();

        $this->onTier($this->project, 'deploy', 'free');
        $this->actingAs($this->owner)->getJson($this->base)->assertJsonPath('canBudget', false)->assertJsonPath('canManage', true);
        $this->actingAs($this->owner)->putJson("{$this->base}/budget", ['monthly_infrastructure_budget' => '5'])->assertForbidden();
    }

    private function metric(Server $server, int $cpu, int $minutesAgo): void
    {
        $metric = new ServerMetric;
        $metric->forceFill(['server_id' => $server->id, 'load_1m' => 0.5, 'load_5m' => 0.5, 'load_15m' => 0.5, 'cpu_percent' => $cpu, 'memory_percent' => 40, 'disk_percent' => 30,
            'network_rx_bytes' => 0, 'network_tx_bytes' => 0, 'disk_read_bytes' => 0, 'disk_write_bytes' => 0, 'process_count' => 80, 'uptime_seconds' => 100, 'recorded_at' => now()->subMinutes($minutesAgo)])->save();
    }
}
