<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Actions\Projects\CreateProject;
use App\Actions\Projects\DeleteProject;
use App\Actions\Projects\EnableService;
use App\Data\Projects\ProjectDetails;
use App\Enums\AccountRole;
use App\Enums\AuditAction;
use App\Models\Account;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProjectActivityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The project's overview lists its own recent changes (not other projects'), and entries outlive the project.
     */
    public function test_the_overview_shows_recent_project_activity(): void
    {
        $owner = User::factory()->create(['name' => 'Olive Owner']);
        $account = Account::factory()->withMember($owner)->create();
        $shop = app(CreateProject::class)->handle($owner, $account, new ProjectDetails('Shop'));
        $blog = app(CreateProject::class)->handle($owner, $account, new ProjectDetails('Blog'));
        app(EnableService::class)->handle($owner, $shop, 'analytics');

        $viewer = User::factory()->create();
        $account->memberships()->forceCreate(['user_id' => $viewer->id, 'role' => AccountRole::Viewer]);
        $response = $this->actingAs($viewer)->getJson("/api/app/projects/{$shop->id}")->assertOk()->assertJsonPath('canViewAuditLog', false);
        $this->assertSame(['Olive Owner'], array_values(array_unique(array_column($response->json('activity'), 'actor'))));
        $descriptions = implode(' | ', array_column($response->json('activity'), 'description'));
        $this->assertStringContainsString('Turned on Analytics for Shop', $descriptions);
        $this->assertStringNotContainsString('Blog', $descriptions);
        $this->actingAs($owner)->getJson("/api/app/projects/{$shop->id}")->assertJsonPath('canViewAuditLog', true);

        app(DeleteProject::class)->handle($owner, $blog);
        $this->assertSame(1, AuditEntry::query()->where('action', AuditAction::ProjectCreated)->whereNull('project_id')->count(), 'Entries outlive their project.');
    }
}
