<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Domain;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SearchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Search finds projects domains and members in the current account only.
     */
    public function test_search_finds_projects_domains_and_members_in_the_current_account_only(): void
    {
        $owner = User::factory()->create(['name' => 'Olive Owner', 'email' => 'olive@example.com']);
        $account = Account::factory()->withMember($owner)->create();
        $shop = Project::factory()->for($account)->create(['name' => 'Storefront']);
        (new Domain)->forceFill(['project_id' => $shop->id, 'hostname' => 'store.example.com', 'verification_token' => 'x'])->save();
        $store = User::factory()->create(['name' => 'Stormy Member', 'email' => 'stormy@example.com']);
        $account->memberships()->forceCreate(['user_id' => $store->id, 'role' => AccountRole::Member]);
        Project::factory()->for(Account::factory()->withMember(User::factory()->create()))->create(['name' => 'Storage secrets']);

        $response = $this->actingAs($owner)->getJson('/api/app/search?q=STO')->assertOk();

        $this->assertSame(['Projects', 'Domains', 'Members'], array_column($response->json('groups'), 'label'));
        $this->assertSame(['Storefront'], array_column($response->json('groups.0.results'), 'title'));
        $this->assertSame(route('projects.show', $shop), $response->json('groups.0.results.0.url'));
        $this->assertSame('store.example.com', $response->json('groups.1.results.0.title'));
        $this->assertSame('Stormy Member', $response->json('groups.2.results.0.title'));
    }

    /**
     * Short queries and wildcards find nothing.
     */
    public function test_short_queries_and_wildcards_find_nothing(): void
    {
        $owner = User::factory()->create();
        Project::factory()->for(Account::factory()->withMember($owner))->create(['name' => 'Storefront']);

        $this->actingAs($owner)->getJson('/api/app/search?q=s')->assertOk()->assertExactJson(['groups' => []]);
        $this->actingAs($owner)->getJson('/api/app/search?q=%25%25')->assertOk()->assertExactJson(['groups' => []]);
        $this->actingAs($owner)->getJson('/api/app/search?q=__')->assertOk()->assertExactJson(['groups' => []]);
    }

    /**
     * Guests cannot search.
     */
    public function test_guests_cannot_search(): void
    {
        $this->getJson('/api/app/search?q=store')->assertUnauthorized();
    }
}
