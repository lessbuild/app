<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Domain\Accounts\Enums\AccountRole;
use App\Domain\Accounts\Models\Account;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Actions\DeleteProject;
use App\Domain\Projects\Actions\EnableService;
use App\Domain\Projects\Data\ProjectDetails;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProjectActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_overview_shows_recent_project_activity_and_the_audit_log_filters_by_project(): void
    {
        $owner = User::factory()->create(['name' => 'Olive Owner']);
        $account = Account::factory()->withMember($owner)->create();
        $shop = app(CreateProject::class)->handle($owner, $account, new ProjectDetails('Shop'));
        $blog = app(CreateProject::class)->handle($owner, $account, new ProjectDetails('Blog'));
        app(EnableService::class)->handle($owner, $shop, 'analytics');

        $viewer = User::factory()->create();
        $account->memberships()->forceCreate(['user_id' => $viewer->id, 'role' => AccountRole::Viewer]);
        $this->actingAs($viewer)->get("/projects/{$shop->id}")
            ->assertOk()
            ->assertSee('Olive Owner')
            ->assertSee('turned on Analytics for Shop')
            ->assertDontSee('created the project Blog')
            ->assertDontSee(__('Full history'));

        $this->actingAs($owner)->get("/account/audit-log?project={$shop->id}")
            ->assertOk()
            ->assertSee('Turned on Analytics for Shop')
            ->assertDontSee('Created the project Blog');
        $this->actingAs($owner)->get('/account/audit-log?project=not-a-project')->assertOk()->assertSee('Created the project Blog');

        app(DeleteProject::class)->handle($owner, $blog);
        $this->assertSame(1, AuditEntry::query()->where('action', AuditAction::ProjectCreated)->whereNull('project_id')->count(), 'Entries outlive their project.');
    }
}
