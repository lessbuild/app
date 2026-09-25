<?php

namespace Tests\Feature\Core;

use App\Core\Models\PlatformUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProjectMembershipManagementTest extends TestCase
{
    private string $workspaceId;

    private string $projectId;

    private string $ownerId;

    private string $ownerMembershipId;

    private string $memberId;

    private string $memberMembershipId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        $this->workspaceId = (string) Str::ulid();
        $this->projectId = (string) Str::ulid();
        $this->ownerId = (string) Str::ulid();
        $this->ownerMembershipId = (string) Str::ulid();
        $this->memberId = (string) Str::ulid();
        $this->memberMembershipId = (string) Str::ulid();

        $this->insertUser($this->ownerId, 'owner@example.test');
        $this->insertUser($this->memberId, 'member@example.test');

        DB::connection('core')->table('workspaces')->insert([
            'id' => $this->workspaceId,
            'owner_user_id' => $this->ownerId,
            'name' => 'Northstar Studio',
            'slug' => 'northstar-studio',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('projects')->insert([
            'id' => $this->projectId,
            'workspace_id' => $this->workspaceId,
            'created_by_user_id' => $this->ownerId,
            'name' => 'Checkout',
            'slug' => 'checkout',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->insertMembership($this->ownerMembershipId, $this->ownerId, 'owner');
        $this->insertMembership($this->memberMembershipId, $this->memberId, 'member');

        Auth::forgetGuards();
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();

        foreach ([
            'workspace_membership_events',
            'workspace_product_access',
            'project_memberships',
            'projects',
            'workspace_memberships',
            'workspaces',
            'users',
        ] as $table) {
            Schema::connection('core')->dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_project_access_can_be_granted_and_revoked_without_changing_workspace_or_app_access(): void
    {
        DB::connection('core')->table('workspace_product_access')->insert([
            'id' => (string) Str::ulid(),
            'membership_id' => $this->memberMembershipId,
            'product' => 'monitor',
            'role' => 'member',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $owner = PlatformUser::query()->findOrFail($this->ownerId);
        $projectMembershipId = (string) Str::ulid();
        DB::connection('core')->table('project_memberships')->insert([
            'id' => $projectMembershipId,
            'project_id' => $this->projectId,
            'user_id' => $this->ownerId,
            'role' => 'owner',
            'status' => 'active',
            'granted_by_user_id' => $this->ownerId,
            'granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($owner, 'platform')
            ->post(route('core.projects.memberships.store', [
                $this->workspaceId,
                $this->projectId,
                $this->memberMembershipId,
            ]))
            ->assertRedirect(route('core.projects.show', [$this->workspaceId, $this->projectId]).'#team-access');

        $this->assertDatabaseHas('project_memberships', [
            'project_id' => $this->projectId,
            'user_id' => $this->memberId,
            'status' => 'active',
            'revoked_at' => null,
        ], 'core');

        $this->actingAs($owner, 'platform')
            ->delete(route('core.projects.memberships.destroy', [
                $this->workspaceId,
                $this->projectId,
                $this->memberMembershipId,
            ]))
            ->assertRedirect(route('core.projects.show', [$this->workspaceId, $this->projectId]).'#team-access');

        $this->assertDatabaseHas('project_memberships', [
            'project_id' => $this->projectId,
            'user_id' => $this->memberId,
            'status' => 'revoked',
        ], 'core');
        $this->assertDatabaseHas('workspace_memberships', [
            'id' => $this->memberMembershipId,
            'status' => 'active',
            'revoked_at' => null,
        ], 'core');
        $this->assertDatabaseHas('workspace_product_access', [
            'membership_id' => $this->memberMembershipId,
            'product' => 'monitor',
            'status' => 'active',
            'revoked_at' => null,
        ], 'core');
        $this->assertDatabaseHas('workspace_membership_events', [
            'event' => 'project_access_granted',
            'subject_user_id' => $this->memberId,
        ], 'core');
        $this->assertDatabaseHas('workspace_membership_events', [
            'event' => 'project_access_revoked',
            'subject_user_id' => $this->memberId,
        ], 'core');
    }

    public function test_non_managers_cannot_change_project_access(): void
    {
        $member = PlatformUser::query()->findOrFail($this->memberId);

        $this->actingAs($member, 'platform')
            ->post(route('core.projects.memberships.store', [
                $this->workspaceId,
                $this->projectId,
                $this->memberMembershipId,
            ]))
            ->assertForbidden();

        $this->assertDatabaseMissing('project_memberships', [
            'project_id' => $this->projectId,
            'user_id' => $this->memberId,
        ], 'core');
    }

    public function test_project_owner_access_cannot_be_revoked(): void
    {
        $this->actingAs(PlatformUser::query()->findOrFail($this->ownerId), 'platform')
            ->delete(route('core.projects.memberships.destroy', [
                $this->workspaceId,
                $this->projectId,
                $this->ownerMembershipId,
            ]))
            ->assertForbidden();

        $this->assertDatabaseMissing('project_memberships', [
            'project_id' => $this->projectId,
            'user_id' => $this->ownerId,
        ], 'core');
    }

    private function createTables(): void
    {
        Schema::connection('core')->create('users', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name')->nullable();
            $table->string('email');
            $table->string('email_normalized')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });
        Schema::connection('core')->create('workspaces', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('owner_user_id');
            $table->string('name');
            $table->string('slug');
            $table->string('status');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('workspace_memberships', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('user_id');
            $table->string('role');
            $table->string('status');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('projects', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('created_by_user_id')->nullable();
            $table->string('name');
            $table->string('slug');
            $table->string('status');
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('project_memberships', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('project_id');
            $table->ulid('user_id');
            $table->string('role');
            $table->string('status');
            $table->ulid('granted_by_user_id')->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'user_id']);
        });
        Schema::connection('core')->create('workspace_product_access', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('membership_id');
            $table->string('product');
            $table->string('role');
            $table->string('status');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('workspace_membership_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('membership_id')->nullable();
            $table->ulid('actor_user_id')->nullable();
            $table->ulid('subject_user_id')->nullable();
            $table->string('event');
            $table->string('previous_role')->nullable();
            $table->string('new_role')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');
        });
    }

    private function insertUser(string $userId, string $email): void
    {
        DB::connection('core')->table('users')->insert([
            'id' => $userId,
            'name' => str($email)->before('@')->headline(),
            'email' => $email,
            'email_normalized' => $email,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertMembership(string $membershipId, string $userId, string $role): void
    {
        DB::connection('core')->table('workspace_memberships')->insert([
            'id' => $membershipId,
            'workspace_id' => $this->workspaceId,
            'user_id' => $userId,
            'role' => $role,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
