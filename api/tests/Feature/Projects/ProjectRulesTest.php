<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Actions\Accounts\SetServiceAccess;
use App\Actions\Projects\CreateEnvironment;
use App\Actions\Projects\CreateProject;
use App\Actions\Projects\DeleteEnvironment;
use App\Actions\Projects\DisableService;
use App\Actions\Projects\EnableService;
use App\Data\Projects\ProjectDetails;
use App\Enums\AccountRole;
use App\Enums\AuditAction;
use App\Enums\EnvironmentKind;
use App\Exceptions\AccountRuleViolation;
use App\Exceptions\ProjectRuleViolation;
use App\Models\Account;
use App\Models\AuditEntry;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProjectRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->account = Account::factory()->withMember($this->owner)->create();
    }

    public function test_projects_start_with_production_and_get_a_slug_unique_in_the_account(): void
    {
        $first = app(CreateProject::class)->handle($this->owner, $this->account, new ProjectDetails(' Web App ', ''));
        $second = app(CreateProject::class)->handle($this->owner, $this->account, new ProjectDetails('Web app'));

        $this->assertSame('Web App', $first->name);
        $this->assertNull($first->description);
        $this->assertSame(['web-app', 'web-app-2'], [$first->slug, $second->slug]);
        $this->assertSame([EnvironmentKind::Production], $first->environments()->pluck('kind')->all());
        $this->assertSame(2, AuditEntry::query()->where('action', AuditAction::ProjectCreated)->count());
    }

    public function test_members_create_projects_but_viewers_cannot(): void
    {
        $member = $this->join(AccountRole::Member);
        $viewer = $this->join(AccountRole::Viewer);

        $this->assertInstanceOf(Project::class, app(CreateProject::class)->handle($member->user, $this->account, new ProjectDetails('Ok')));
        $this->expectException(AuthorizationException::class);
        app(CreateProject::class)->handle($viewer->user, $this->account, new ProjectDetails('Nope'));
    }

    public function test_environments_keep_one_production_and_unique_names(): void
    {
        $project = Project::factory()->for($this->account)->create();
        $staging = app(CreateEnvironment::class)->handle($this->owner, $project, 'Staging', EnvironmentKind::Staging);

        foreach ([
            fn () => app(CreateEnvironment::class)->handle($this->owner, $project, 'Prod 2', EnvironmentKind::Production),
            fn () => app(CreateEnvironment::class)->handle($this->owner, $project, 'staging', EnvironmentKind::Staging),
            fn () => app(DeleteEnvironment::class)->handle($this->owner, $project->environments()->where('kind', EnvironmentKind::Production)->sole()),
        ] as $breaksARule) {
            try {
                $breaksARule();
                $this->fail('Expected a ProjectRuleViolation.');
            } catch (ProjectRuleViolation) {
                $this->addToAssertionCount(1);
            }
        }

        app(DeleteEnvironment::class)->handle($this->owner, $staging);
        $this->assertSame(1, $project->environments()->count());
    }

    public function test_services_are_enabled_once_and_unknown_ones_are_refused(): void
    {
        $project = Project::factory()->for($this->account)->create();

        $this->assertTrue(app(EnableService::class)->handle($this->owner, $project, 'monitoring'));
        $this->assertFalse(app(EnableService::class)->handle($this->owner, $project, 'monitoring'));
        $this->assertTrue($project->hasService('monitoring'));
        $this->assertTrue(app(DisableService::class)->handle($this->owner, $project, 'monitoring'));
        $this->assertFalse($project->hasService('monitoring'));
        $this->assertSame(['service.disabled', 'service.enabled'], AuditEntry::query()->where('action', 'like', 'service.%')->orderBy('action')->pluck('action')->map->value->all());

        $this->expectException(ProjectRuleViolation::class);
        app(EnableService::class)->handle($this->owner, $project, 'blockchain');
    }

    public function test_members_can_be_limited_to_some_services(): void
    {
        $member = $this->join(AccountRole::Member);
        $project = Project::factory()->for($this->account)->create();

        app(SetServiceAccess::class)->handle($this->owner, $member, ['analytics', 'made-up']);
        $this->assertSame(['analytics'], $member->refresh()->service_access);

        $this->assertTrue($member->user->can('useService', [$project, 'analytics']));
        $this->assertFalse($member->user->can('useService', [$project, 'deploy']));
        $this->assertTrue(app(EnableService::class)->handle($member->user, $project, 'analytics'));
        try {
            app(EnableService::class)->handle($member->user, $project, 'deploy');
            $this->fail('A member limited to Analytics must not enable Deploy.');
        } catch (AuthorizationException) {
            $this->assertFalse($project->hasService('deploy'));
        }

        app(SetServiceAccess::class)->handle($this->owner, $member, null);
        $this->assertTrue($member->user->can('useService', [$project, 'deploy']));
        $this->assertSame(2, AuditEntry::query()->where('action', AuditAction::MemberServiceAccessChanged)->count());
    }

    public function test_owners_and_admins_always_have_every_service(): void
    {
        $admin = $this->join(AccountRole::Admin);

        $this->expectException(AccountRuleViolation::class);
        app(SetServiceAccess::class)->handle($this->owner, $admin, ['analytics']);
    }

    public function test_outsiders_cannot_see_projects_or_services(): void
    {
        $project = Project::factory()->for($this->account)->create();
        $stranger = User::factory()->create();

        $this->assertFalse($stranger->can('view', $project));
        $this->assertFalse($stranger->can('useService', [$project, 'deploy']));
    }

    private function join(AccountRole $role): Membership
    {
        return $this->account->memberships()->forceCreate(['user_id' => User::factory()->create()->id, 'role' => $role]);
    }
}
