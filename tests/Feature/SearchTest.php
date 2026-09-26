<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Accounts\Enums\AccountRole;
use App\Domain\Accounts\Models\Account;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Domain;
use App\Domain\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_finds_projects_domains_and_members_in_the_current_account_only(): void
    {
        $owner = User::factory()->create(['name' => 'Olive Owner']);
        $account = Account::factory()->withMember($owner)->create();
        $shop = Project::factory()->for($account)->create(['name' => 'Storefront']);
        (new Domain)->forceFill(['project_id' => $shop->id, 'hostname' => 'store.example.com', 'verification_token' => 'x'])->save();
        $store = User::factory()->create(['name' => 'Stormy Member', 'email' => 'stormy@example.com']);
        $account->memberships()->forceCreate(['user_id' => $store->id, 'role' => AccountRole::Member]);
        Project::factory()->for(Account::factory()->withMember(User::factory()->create()))->create(['name' => 'Storage secrets']);

        $response = $this->actingAs($owner)->getJson('/search?q=STO')->assertOk();

        $this->assertSame(['Projects', 'Domains', 'Members'], array_column($response->json('groups'), 'label'));
        $this->assertSame(['Storefront'], array_column($response->json('groups.0.results'), 'title'));
        $this->assertSame(route('projects.show', $shop), $response->json('groups.0.results.0.url'));
        $this->assertSame('store.example.com', $response->json('groups.1.results.0.title'));
        $this->assertSame('Stormy Member', $response->json('groups.2.results.0.title'));
    }

    public function test_short_queries_and_wildcards_find_nothing(): void
    {
        $owner = User::factory()->create();
        Project::factory()->for(Account::factory()->withMember($owner))->create(['name' => 'Storefront']);

        $this->actingAs($owner)->getJson('/search?q=s')->assertOk()->assertExactJson(['groups' => []]);
        $this->actingAs($owner)->getJson('/search?q=%25%25')->assertOk()->assertExactJson(['groups' => []]);
        $this->actingAs($owner)->getJson('/search?q=__')->assertOk()->assertExactJson(['groups' => []]);
    }

    public function test_guests_cannot_search(): void
    {
        $this->getJson('/search?q=store')->assertUnauthorized();
    }

    public function test_every_signed_in_page_has_the_command_palette(): void
    {
        $owner = User::factory()->create();
        Account::factory()->withMember($owner)->create();

        $this->actingAs($owner)->get('/dashboard')
            ->assertOk()
            ->assertSee('data-signal-command-palette', false)
            ->assertSee('data-signal-command-search-url="'.route('search').'"', false)
            ->assertSee(route('projects.create'), false);
    }
}
