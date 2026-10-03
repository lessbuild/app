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

        $this->actingAs($owner)->get('/account/billing?tab=costs')->assertOk()
            ->assertSeeInOrder(['Costs by project', 'Blog', '$9.50', '$5.00', 'Shop', '$9.50', '$29.00', 'Servers no project uses', '€8.00'])
            ->assertSee('€8.00 + $34.00')->assertSee('$36.20')->assertSee('1 server has no known price');
    }
}
