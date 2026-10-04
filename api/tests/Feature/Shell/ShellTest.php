<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class ShellTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * The frame around a service page: the services, the service's sections inside the project (as paths), the
     * account links, the switchers, and the person's language.
     */
    public function test_the_shell_describes_a_project_service_page(): void
    {
        $project = Project::factory()->withServices(['deploy'])->create(['name' => 'Storefront']);
        $owner = $this->ownerOf($project);
        $owner->forceFill(['current_account_id' => $project->account_id, 'locale' => 'fr'])->save();

        $shell = $this->actingAs($owner)->getJson("/api/app/shell?project={$project->id}&service=deploy")->assertOk()->json();

        $this->assertSame('fr', $shell['locale']);
        $this->assertSame(['id' => $project->id, 'name' => 'Storefront', 'isSample' => false], $shell['project']);
        $this->assertSame([['id' => $project->id, 'name' => 'Storefront']], $shell['projects']);
        $this->assertContains('Deploy', array_column($shell['primaryNav'], 'label'));
        $this->assertContains("/projects/{$project->id}/services/deploy", array_column($shell['primaryNav'], 'url'));
        $this->assertStringStartsWith("/projects/{$project->id}/deploy", $shell['sectionNav'][0]['url']);
        $this->assertNotEmpty($shell['accountLinks']);
        $this->assertTrue($shell['canCreateProject']);
    }

    /**
     * Project pages, account pages and personal settings each get their own section navigation; elsewhere there's none.
     */
    public function test_areas_have_their_own_sections(): void
    {
        $project = Project::factory()->create();
        $owner = $this->ownerOf($project);
        $owner->forceFill(['current_account_id' => $project->account_id])->save();

        $this->actingAs($owner)->getJson("/api/app/shell?project={$project->id}")->assertOk()->assertJsonPath('sectionNav.0.url', "/projects/{$project->id}");
        $this->actingAs($owner)->getJson('/api/app/shell?area=account')->assertOk()->assertJsonPath('sectionNav.1.url', '/account/members');
        $this->actingAs($owner)->getJson('/api/app/shell?area=settings')->assertOk()->assertJsonPath('sectionNav.0.url', '/settings/profile');
        $this->actingAs($owner)->getJson('/api/app/shell')->assertOk()->assertJsonPath('sectionNav', [])->assertJsonPath('project', null);
        $this->actingAs($owner)->getJson('/api/app/shell?area=nowhere')->assertUnprocessable();
    }

    /**
     * Opening a project from another of the person's accounts makes that account current; strangers get a 404; guests
     * a 401.
     */
    public function test_projects_switch_the_account_and_stay_private(): void
    {
        $project = Project::factory()->create();
        $person = $this->ownerOf($project);
        $other = Account::factory()->create();
        (new Membership)->forceFill(['account_id' => $other->id, 'user_id' => $person->id, 'role' => AccountRole::Owner])->save();
        $person->forceFill(['current_account_id' => $other->id])->save();

        $this->actingAs($person)->getJson("/api/app/shell?project={$project->id}")->assertOk()->assertJsonPath('account.id', $project->account_id);
        $this->assertSame($project->account_id, $person->refresh()->current_account_id);

        $this->actingAs(User::factory()->create())->getJson("/api/app/shell?project={$project->id}")->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/app/shell')->assertUnauthorized();
    }

    /**
     * An unverified email or an account that needs a second factor answers 409 with where to go, so the app can take
     * the person there.
     */
    public function test_checks_that_send_people_elsewhere_answer_409(): void
    {
        $project = Project::factory()->create();
        $owner = $this->ownerOf($project);
        $owner->forceFill(['current_account_id' => $project->account_id, 'email_verified_at' => null])->save();
        $this->actingAs($owner)->getJson('/api/app/shell')->assertStatus(409)->assertJsonPath('redirect', '/email/verify');

        $owner->forceFill(['email_verified_at' => now()])->save();
        $project->account->forceFill(['require_two_factor' => true])->save();
        $this->actingAs($owner)->getJson('/api/app/shell')->assertStatus(409)->assertJsonPath('redirect', '/settings/security');
    }

    /**
     * The account switcher lists your accounts and switches between them.
     */
    public function test_the_account_switcher_lists_your_accounts_and_switches_between_them(): void
    {
        $user = User::factory()->create();
        $acme = Account::factory()->withMember($user)->create(['name' => 'Acme']);
        $globex = Account::factory()->withMember($user)->create(['name' => 'Globex']);
        $stranger = Account::factory()->withMember(User::factory()->create())->create(['name' => 'Initech']);

        $accounts = array_column((array) $this->actingAs($user)->getJson('/api/app/shell')->assertOk()->json('accounts'), 'name');
        $this->assertEqualsCanonicalizing(['Acme', 'Globex'], $accounts);

        $this->actingAs($user)->postJson("/api/app/accounts/{$globex->id}/switch")->assertOk()->assertJsonPath('redirect', '/dashboard');
        $this->assertSame($globex->id, $user->refresh()->current_account_id);
        $this->actingAs($user)->postJson("/api/app/accounts/{$stranger->id}/switch")->assertNotFound();
        $this->assertNotSame($acme->id, $user->refresh()->current_account_id);
    }

    /**
     * The topbar shows the platform areas and the sections of the current area.
     */
    public function test_the_topbar_shows_the_platform_areas_and_the_sections_of_the_current_area(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->withMember($owner)->create(['name' => 'Acme']);
        $project = Project::factory()->for($account)->withServices(['deploy'])->create(['name' => 'Storefront']);
        $blog = Project::factory()->for($account)->create(['name' => 'Blog']);

        // Row one: inside a project, each service tab opens that project's service (or its enable page).
        $shell = $this->actingAs($owner)->getJson("/api/app/shell?project={$project->id}")->assertOk();
        $this->assertContains("/projects/{$project->id}/services/monitoring", array_column((array) $shell->json('primaryNav'), 'url'));
        $this->assertContains($blog->id, array_column((array) $shell->json('projects'), 'id'));
        $this->assertContains("/projects/{$project->id}/domains", array_column((array) $shell->json('sectionNav'), 'url'));
        $this->assertContains("/projects/{$project->id}/settings", array_column((array) $shell->json('sectionNav'), 'url'));
        $this->assertContains('/account/audit-log', array_column((array) $shell->json('accountLinks'), 'url'));

        // Outside a project, the tabs open each service across the account.
        $this->assertContains('/services/analytics', array_column((array) $this->actingAs($owner)->getJson('/api/app/shell')->json('primaryNav'), 'url'));

        // Row two follows the area: account pages, then personal settings.
        $this->actingAs($owner)->getJson('/api/app/shell?area=account')->assertJsonPath('sectionLabel', __('Account sections'));
        $this->assertContains('/settings/privacy', array_column((array) $this->actingAs($owner)->getJson('/api/app/shell?area=settings')->json('sectionNav'), 'url'));
    }

    /**
     * Members limited to some services only get those tabs.
     */
    public function test_members_limited_to_some_services_only_get_those_tabs(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->withMember($owner)->create();
        $project = Project::factory()->for($account)->withServices(['deploy', 'analytics'])->create();
        $member = User::factory()->create();
        $account->memberships()->forceCreate(['user_id' => $member->id, 'role' => AccountRole::Member, 'service_access' => ['analytics']]);
        $member->forceFill(['current_account_id' => $account->id])->save();

        $shell = $this->actingAs($member)->getJson("/api/app/shell?project={$project->id}")->assertOk();
        $tabs = array_column((array) $shell->json('primaryNav'), 'url');
        $this->assertContains("/projects/{$project->id}/services/analytics", $tabs);
        $this->assertNotContains("/projects/{$project->id}/services/deploy", $tabs);
        $links = array_column((array) $shell->json('accountLinks'), 'url');
        $this->assertNotContains('/account/audit-log', $links);
        $this->assertNotContains('/account/api-tokens', $links);
        $this->actingAs($member)->getJson('/api/app/services/deploy')->assertForbidden();
    }

    /**
     * A service page lists the projects using it.
     */
    public function test_a_service_page_lists_the_projects_using_it(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->withMember($owner)->create();
        $using = Project::factory()->for($account)->withServices(['monitoring'])->create(['name' => 'Storefront']);
        $notUsing = Project::factory()->for($account)->create(['name' => 'Blog']);

        $page = $this->actingAs($owner)->getJson('/api/app/services/monitoring')->assertOk()->assertJsonPath('service.name', 'Monitoring');
        $this->assertSame(['Storefront', 'Blog'], array_column((array) $page->json('projects'), 'projectName'));
        $this->assertSame([true, false], array_column((array) $page->json('projects'), 'enabled'));
        $this->assertSame([$using->id, $notUsing->id], array_column((array) $page->json('projects'), 'projectId'));
        $this->actingAs($owner)->getJson('/api/app/services/blockchain')->assertNotFound();
    }
}
