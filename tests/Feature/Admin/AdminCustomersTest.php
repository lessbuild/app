<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Pages\Business;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Account;
use App\Models\BillingAccount;
use App\Models\PlatformAdminEvent;
use App\Models\Project;
use App\Models\SignInEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class AdminCustomersTest extends TestCase
{
    use AdminHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    public function test_business_analytics_counts_accounts_revenue_and_tiers(): void
    {
        $admin = $this->admin();
        $paying = Project::factory()->withServices(['deploy'])->create();
        $this->onTier($paying, 'deploy', 'pro');
        $this->onTier($paying, 'monitoring', 'pro');
        Project::factory()->create();

        $page = $this->as($admin)->get('/admin/business')->assertOk();
        $page->assertSee('Estimated monthly revenue')->assertSee('1 paying account')->assertSee('Deploy tiers')->assertSee('Sign-ups per day');
        $component = Livewire::test(Business::class);
        $totals = $component->viewData('totals');
        $this->assertSame([3, 1], [$totals['accounts'], $totals['paid_accounts']]);
        $this->assertGreaterThan(0, $totals['mrr_cents']);
        $this->assertSame(1, $component->viewData('services')['deploy']['tiers']['pro']['accounts']);
        $this->assertSame(2, $component->viewData('services')['deploy']['tiers']['free']['accounts']);
        $this->assertCount(30, $component->viewData('trend'));
        $this->assertTrue(Cache::has('admin:business-analytics'));
    }

    /**
     * Check that the sign-up funnel follows new accounts from sign-up to paying, and that sample projects and old
     * accounts don't count.
     *
     * @return void
     */
    public function test_the_sign_up_funnel_follows_new_accounts(): void
    {
        $admin = $this->admin();
        $paying = Project::factory()->withServices(['deploy'])->create();
        $this->onTier($paying, 'deploy', 'pro');
        Project::factory()->create(['is_sample' => true]);
        $old = Project::factory()->create();
        $old->account->forceFill(['created_at' => now()->subDays(45)])->save();

        $this->as($admin)->get('/admin/business')->assertOk()->assertSee('Sign-up funnel');
        /** @var list<array{label: string, accounts: int}> $steps */
        $steps = Livewire::test(Business::class)->viewData('funnel');
        $funnel = array_column($steps, 'accounts', 'label');
        $this->assertSame(Account::query()->where('created_at', '>=', now()->subDays(30))->count(), $funnel['Signed up']);
        $this->assertSame(1, $funnel['Created a project']);
        $this->assertSame(0, $funnel['Deployed']);
        $this->assertSame(1, $funnel['Pays']);
    }

    public function test_support_finds_and_opens_people_and_accounts_leaving_a_trail(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create(['name' => 'Carla Customer', 'email' => 'carla@shop.test']);
        $account = Account::factory()->withMember($customer)->create(['name' => 'Carla’s Shop']);
        Project::factory()->for($account)->withServices(['analytics'])->create(['name' => 'Storefront']);
        $billing = new BillingAccount;
        $billing->forceFill(['account_id' => $account->id, 'stripe_customer_id' => 'cus_123', 'status' => 'active'])->save();
        $this->onTier($account, 'analytics', 'growth');
        $signIn = new SignInEvent;
        $signIn->forceFill(['user_id' => $customer->id, 'succeeded' => true, 'two_factor' => false, 'ip_address' => '198.51.100.7'])->save();

        $this->as($admin)->get('/admin/people')->assertOk();
        Livewire::test(ListUsers::class)->searchTable('CARLA@')->assertCanSeeTableRecords([$customer])->assertCanNotSeeTableRecords([$admin]);
        Livewire::test(ListAccounts::class)->searchTable('cus_123')->assertCanSeeTableRecords([$account])->assertCountTableRecords(1);
        Livewire::test(ListAccounts::class)->searchTable('carla’s')->assertCanSeeTableRecords([$account]);

        $this->as($admin)->get("/admin/accounts/{$account->id}")->assertOk()
            ->assertSee('carla@shop.test')->assertSee('Storefront')->assertSee('analytics')->assertSee('cus_123')->assertSee('growth');
        $this->as($admin)->get("/admin/people/{$customer->id}")->assertOk()->assertSee('198.51.100.7')->assertSee('Carla’s Shop');
        $this->as($admin)->get('/admin/people/01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertNotFound();

        $this->assertSame(['Opened the account Carla’s Shop.', 'Opened carla@shop.test.'], PlatformAdminEvent::query()->where('action', 'customer.viewed')->orderBy('id')->pluck('description')->all());
        $this->actingAs($customer)->get("/admin/accounts/{$account->id}")->assertNotFound();
    }
}
