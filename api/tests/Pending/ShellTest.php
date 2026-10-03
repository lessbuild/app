<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_account_switcher_lists_your_accounts_and_switches_between_them(): void
    {
        $user = User::factory()->create();
        $acme = Account::factory()->withMember($user)->create(['name' => 'Acme']);
        $globex = Account::factory()->withMember($user)->create(['name' => 'Globex']);
        $stranger = Account::factory()->withMember(User::factory()->create())->create(['name' => 'Initech']);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee(route('accounts.switch', $globex->id), false)
            ->assertDontSee('Initech');

        $this->actingAs($user)->post("/accounts/{$globex->id}/switch")->assertRedirect('/dashboard');
        $this->assertSame($globex->id, $user->refresh()->current_account_id);
        $this->actingAs($user)->post("/accounts/{$stranger->id}/switch")->assertNotFound();
        $this->assertNotSame($acme->id, $user->refresh()->current_account_id);
    }

    public function test_the_topbar_shows_the_platform_areas_and_the_sections_of_the_current_area(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->withMember($owner)->create(['name' => 'Acme']);
        $project = Project::factory()->for($account)->withServices(['deploy'])->create(['name' => 'Storefront']);
        $blog = Project::factory()->for($account)->create(['name' => 'Blog']);

        // Row one: inside a project, each service tab opens that project's service (or its enable page).
        $this->actingAs($owner)->get("/projects/{$project->id}")
            ->assertOk()
            ->assertSee(route('projects.services.show', [$project->id, 'monitoring']), false)
            ->assertSee(route('projects.show', $blog->id), false)
            ->assertSee(route('projects.domains', $project), false)
            ->assertSee(route('projects.settings', $project), false)
            ->assertSee(route('account.audit-log'), false);

        // Outside a project, the tabs open each service across the account.
        $this->actingAs($owner)->get('/dashboard')->assertOk()->assertSee(route('services.show', 'analytics'), false);

        // Row two follows the area: account pages, then personal settings.
        $this->actingAs($owner)->get('/account/members')->assertOk()->assertSee('aria-label="'.__('Account sections').'"', false);
        $this->actingAs($owner)->get('/settings/profile')->assertOk()->assertSee(route('settings.privacy'), false);
    }

    public function test_members_limited_to_some_services_only_get_those_tabs(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->withMember($owner)->create();
        $project = Project::factory()->for($account)->withServices(['deploy', 'analytics'])->create();
        $member = User::factory()->create();
        $account->memberships()->forceCreate(['user_id' => $member->id, 'role' => AccountRole::Member, 'service_access' => ['analytics']]);
        $member->forceFill(['current_account_id' => $account->id])->save();

        $this->actingAs($member)->get("/projects/{$project->id}")
            ->assertOk()
            ->assertSee(route('projects.services.show', [$project->id, 'analytics']), false)
            ->assertDontSee('href="'.route('projects.services.show', [$project->id, 'deploy']).'"', false)
            ->assertDontSee(route('account.audit-log'), false)
            ->assertDontSee(route('account.api-tokens'), false);
        $this->actingAs($member)->get('/services/deploy')->assertForbidden();
    }

    public function test_a_service_page_lists_the_projects_using_it(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->withMember($owner)->create();
        $using = Project::factory()->for($account)->withServices(['monitoring'])->create(['name' => 'Storefront']);
        $notUsing = Project::factory()->for($account)->create(['name' => 'Blog']);

        $this->actingAs($owner)->get('/services/monitoring')
            ->assertOk()
            ->assertSeeInOrder(['Storefront', 'Blog'])
            ->assertSee(route('projects.services.show', [$using->id, 'monitoring']), false)
            ->assertSee(route('projects.services.store', [$notUsing->id, 'monitoring']), false);
        $this->actingAs($owner)->get('/services/blockchain')->assertNotFound();
    }
}
