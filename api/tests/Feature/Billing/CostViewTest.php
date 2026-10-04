<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Project;
use App\Models\Provider;
use App\Models\ProviderBill;
use App\Models\Server;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class CostViewTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check each project gets an even share of the plans of the services it uses and the cost of the servers its
     * websites run on (split when shared), with unused servers apart and last month's cloud invoices beside them.
     *
     * @return void
     */
    /**
     * Costs are split by project: platform charges by the services each uses, servers by the websites on them.
     */
    public function test_costs_are_split_by_project(): void
    {
        $shop = Project::factory()->withServices(['deploy'])->create(['name' => 'Shop']);
        $blog = Project::factory()->withServices(['deploy'])->create(['name' => 'Blog', 'account_id' => $shop->account_id]);
        $owner = $this->ownerOf($shop);
        $owner->forceFill(['current_account_id' => $shop->account_id])->save();
        $this->onTier($shop, 'deploy', 'pro');
        $provider = Provider::factory()->create(['account_id' => $shop->account_id]);
        $own = Server::factory()->create(['account_id' => $shop->account_id, 'provider_id' => $provider->id, 'monthly_cost' => 24, 'monthly_cost_currency' => 'USD']);
        $shared = Server::factory()->create(['account_id' => $shop->account_id, 'provider_id' => $provider->id, 'monthly_cost' => 10, 'monthly_cost_currency' => 'USD']);
        Server::factory()->create(['account_id' => $shop->account_id, 'monthly_cost' => 8, 'monthly_cost_currency' => 'EUR']);
        Server::factory()->create(['account_id' => $shop->account_id, 'monthly_cost' => null]);
        $shopProduction = $shop->environments()->where('slug', 'production')->firstOrFail();
        $blogProduction = $blog->environments()->where('slug', 'production')->firstOrFail();
        Website::factory()->create(['server_id' => $own->id, 'environment_id' => $shopProduction->id, 'account_id' => $shop->account_id]);
        Website::factory()->create(['server_id' => $shared->id, 'environment_id' => $shopProduction->id, 'account_id' => $shop->account_id]);
        Website::factory()->create(['server_id' => $shared->id, 'environment_id' => $blogProduction->id, 'account_id' => $shop->account_id]);
        $bill = new ProviderBill;
        $bill->forceFill(['provider_id' => $provider->id, 'period' => now()->subMonthNoOverflow()->format('Y-m'), 'amount' => 36.2, 'currency' => 'USD', 'final' => true])->save();

        $costs = $this->actingAs($owner)->getJson('/api/app/account/billing')->assertOk()->json('costs');
        $this->assertEquals([['name' => 'Blog', 'platform' => 9.5, 'cloud' => ['USD' => 5.0]], ['name' => 'Shop', 'platform' => 9.5, 'cloud' => ['USD' => 29.0]]], $costs['projects']);
        $this->assertEquals(['EUR' => 8.0], $costs['unassigned']);
        $this->assertEqualsCanonicalizing(['EUR' => 8.0, 'USD' => 34.0], $costs['cloudTotal']);
        $this->assertEquals(['USD' => 36.2], $costs['billed']);
        $this->assertSame(1, $costs['unpriced']);
    }
}
