<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AuditEntry;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PinsAndArchivingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Pinned projects come first for the person who pinned them only; archived projects leave the list, the switcher
     * and service lists, show on their own list, and come back when restored.
     */
    public function test_projects_can_be_pinned_archived_and_restored(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->withMember($owner)->create();
        $owner->forceFill(['current_account_id' => $account->id])->save();
        $alpha = Project::factory()->create(['account_id' => $account->id, 'name' => 'Alpha']);
        $zulu = Project::factory()->create(['account_id' => $account->id, 'name' => 'Zulu']);
        $colleague = User::factory()->create(['current_account_id' => $account->id]);
        $account->memberships()->forceCreate(['user_id' => $colleague->id, 'role' => AccountRole::Viewer]);

        $this->actingAs($owner)->putJson("/api/app/projects/{$zulu->id}/pin", ['pinned' => true])->assertOk();
        $this->actingAs($owner)->getJson('/api/app/dashboard')->assertOk()
            ->assertJsonPath('projects.0.name', 'Zulu')->assertJsonPath('projects.0.pinned', true)->assertJsonPath('projects.1.name', 'Alpha');
        $this->actingAs($colleague)->getJson('/api/app/dashboard')->assertJsonPath('projects.0.name', 'Alpha');

        $this->actingAs($colleague)->putJson("/api/app/projects/{$alpha->id}/archive", ['archived' => true])->assertForbidden();
        $this->actingAs($owner)->putJson("/api/app/projects/{$alpha->id}/archive", ['archived' => true])->assertOk()->assertJsonPath('redirect', '/dashboard?projects=archived');
        $this->assertNotNull($alpha->refresh()->archived_at);
        $this->assertTrue(AuditEntry::query()->where('action', 'project.archived')->exists());

        $this->actingAs($owner)->getJson('/api/app/dashboard')->assertJsonCount(1, 'projects')->assertJsonPath('archivedCount', 1);
        $this->actingAs($owner)->getJson('/api/app/dashboard?projects=archived')->assertJsonPath('projects.0.name', 'Alpha')->assertJsonPath('projects.0.archived', true)->assertJsonPath('showingArchived', true);
        $this->actingAs($owner)->getJson("/api/app/projects/{$alpha->id}")->assertOk()->assertJsonPath('overview.project.archivedAt', $alpha->archived_at?->toIso8601String());

        $this->actingAs($owner)->putJson("/api/app/projects/{$alpha->id}/archive", ['archived' => false])->assertOk();
        $this->actingAs($owner)->getJson('/api/app/dashboard')->assertJsonCount(2, 'projects')->assertJsonPath('archivedCount', 0);
    }
}
