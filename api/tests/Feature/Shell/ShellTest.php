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
}
