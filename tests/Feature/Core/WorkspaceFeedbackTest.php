<?php

namespace Tests\Feature\Core;

use App\Core\Models\PlatformUser;
use App\Core\Models\WorkspaceFeedback;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WorkspaceFeedbackTest extends TestCase
{
    private string $ownerId;

    private string $workspaceId;

    private string $ownerMembershipId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();

        $this->ownerId = (string) Str::ulid();
        $this->workspaceId = (string) Str::ulid();
        $this->ownerMembershipId = (string) Str::ulid();

        $this->insertUser($this->ownerId, 'Workspace Owner');
        DB::connection('core')->table('workspaces')->insert([
            'id' => $this->workspaceId,
            'owner_user_id' => $this->ownerId,
            'name' => 'Northstar Studio',
            'slug' => 'northstar-studio',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->insertMembership($this->ownerMembershipId, $this->ownerId, 'owner');
        $this->grantProduct($this->ownerMembershipId, 'deployer');

        Auth::forgetGuards();
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();

        foreach (['workspace_feedback', 'workspace_product_access', 'workspace_memberships', 'workspaces', 'users'] as $table) {
            Schema::connection('core')->dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_member_can_send_encrypted_workspace_feedback_for_an_app_they_can_access(): void
    {
        $owner = PlatformUser::query()->findOrFail($this->ownerId);

        $this->actingAs($owner, 'platform')
            ->post(route('core.workspace.feedback.store', $this->workspaceId), [
                'product' => 'deployer',
                'category' => 'bug',
                'severity' => 'high',
                'title' => 'Preview cleanup is stuck',
                'description' => 'A private deployment detail.',
                'reproduction_steps' => 'Open the preview and inspect its cleanup state.',
                'page' => '/projects',
            ])
            ->assertRedirect(route('core.workspace.feedback.index', $this->workspaceId));

        $feedback = WorkspaceFeedback::query()->sole();
        $this->assertSame('deployer', $feedback->product);
        $this->assertSame('A private deployment detail.', $feedback->description);
        $this->assertNotSame('A private deployment detail.', DB::connection('core')->table('workspace_feedback')->value('description'));
    }

    public function test_members_see_only_their_own_submissions_while_workspace_admin_can_review(): void
    {
        $owner = PlatformUser::query()->findOrFail($this->ownerId);
        $ownerFeedback = WorkspaceFeedback::query()->create([
            'workspace_id' => $this->workspaceId,
            'user_id' => $this->ownerId,
            'product' => 'deployer',
            'category' => 'idea',
            'severity' => 'normal',
            'title' => 'Owner-only feedback',
            'description' => 'Visible to workspace managers.',
            'status' => 'open',
        ]);
        $monitorFeedback = WorkspaceFeedback::query()->create([
            'workspace_id' => $this->workspaceId,
            'user_id' => $this->ownerId,
            'product' => 'monitor',
            'category' => 'bug',
            'severity' => 'normal',
            'title' => 'Monitor review target',
            'description' => 'Visible to Monitor product admins.',
            'status' => 'open',
        ]);

        $viewerId = (string) Str::ulid();
        $viewerMembershipId = (string) Str::ulid();
        $this->insertUser($viewerId, 'Workspace Viewer');
        $this->insertMembership($viewerMembershipId, $viewerId, 'viewer');
        $this->grantProduct($viewerMembershipId, 'monitor', 'admin');
        $viewer = PlatformUser::query()->findOrFail($viewerId);

        $this->actingAs($viewer, 'platform')
            ->get(route('core.workspace.feedback.index', $this->workspaceId))
            ->assertOk()
            ->assertSee('Monitor review target')
            ->assertDontSee('Owner-only feedback');

        $this->patch(route('core.workspace.feedback.update', [$this->workspaceId, $monitorFeedback]), [
            'status' => 'reviewing',
            'review_response' => 'Monitor team is investigating.',
        ])->assertRedirect(route('core.workspace.feedback.index', $this->workspaceId));

        $this->patch(route('core.workspace.feedback.update', [$this->workspaceId, $ownerFeedback]), [
            'status' => 'closed',
            'review_response' => 'This must remain unchanged.',
        ])->assertForbidden();

        $viewerResponse = $this->post(route('core.workspace.feedback.store', $this->workspaceId), [
            'product' => 'monitor',
            'category' => 'usability',
            'severity' => 'normal',
            'title' => 'Viewer feedback',
            'description' => 'A viewer can see this own submission.',
        ]);
        $viewerResponse->assertRedirect(route('core.workspace.feedback.index', $this->workspaceId));

        $this->actingAs($owner, 'platform')
            ->patch(route('core.workspace.feedback.update', [$this->workspaceId, $ownerFeedback]), [
                'status' => 'planned',
                'review_response' => 'Added to the next review cycle.',
            ])
            ->assertRedirect(route('core.workspace.feedback.index', $this->workspaceId));

        $ownerFeedback->refresh();
        $this->assertSame('planned', $ownerFeedback->status);
        $this->assertSame($this->ownerId, $ownerFeedback->reviewed_by_user_id);
        $this->assertSame('Added to the next review cycle.', $ownerFeedback->review_response);
    }

    public function test_product_access_and_workspace_membership_are_required(): void
    {
        $viewerId = (string) Str::ulid();
        $viewerMembershipId = (string) Str::ulid();
        $this->insertUser($viewerId, 'No Product Access');
        $this->insertMembership($viewerMembershipId, $viewerId, 'viewer');
        $viewer = PlatformUser::query()->findOrFail($viewerId);

        $this->actingAs($viewer, 'platform')
            ->post(route('core.workspace.feedback.store', $this->workspaceId), [
                'product' => 'analytics',
                'category' => 'idea',
                'severity' => 'low',
                'title' => 'Unentitled feedback',
                'description' => 'This should not be stored.',
            ])
            ->assertForbidden();

        $otherWorkspace = (string) Str::ulid();
        DB::connection('core')->table('workspaces')->insert([
            'id' => $otherWorkspace,
            'owner_user_id' => $this->ownerId,
            'name' => 'Other workspace',
            'slug' => 'other-workspace',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get(route('core.workspace.feedback.index', $otherWorkspace))->assertNotFound();
        $this->assertDatabaseMissing('workspace_feedback', ['title' => 'Unentitled feedback'], 'core');
    }

    private function createTables(): void
    {
        foreach (['workspace_feedback', 'workspace_product_access', 'workspace_memberships', 'workspaces', 'users'] as $table) {
            Schema::connection('core')->dropIfExists($table);
        }

        Schema::connection('core')->create('users', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('email_normalized')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });
        Schema::connection('core')->create('workspaces', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('owner_user_id', 26);
            $table->string('name');
            $table->string('slug');
            $table->string('status')->default('active');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('workspace_memberships', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('workspace_id', 26);
            $table->string('user_id', 26);
            $table->string('role');
            $table->string('status')->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('workspace_product_access', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('membership_id', 26);
            $table->string('product', 24);
            $table->string('role', 24)->default('member');
            $table->string('status')->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('workspace_feedback', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('workspace_id', 26);
            $table->string('user_id', 26);
            $table->string('reviewed_by_user_id', 26)->nullable();
            $table->string('product', 24);
            $table->string('category', 20);
            $table->string('severity', 20);
            $table->string('status', 20);
            $table->string('title', 160);
            $table->text('description');
            $table->text('reproduction_steps')->nullable();
            $table->text('review_response')->nullable();
            $table->string('page', 500)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    private function insertUser(string $id, string $name): void
    {
        $email = Str::slug($name).'.'.Str::random(4).'@example.test';

        DB::connection('core')->table('users')->insert([
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'email_normalized' => $email,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertMembership(string $id, string $userId, string $role): void
    {
        DB::connection('core')->table('workspace_memberships')->insert([
            'id' => $id,
            'workspace_id' => $this->workspaceId,
            'user_id' => $userId,
            'role' => $role,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function grantProduct(string $membershipId, string $product, string $role = 'member'): void
    {
        DB::connection('core')->table('workspace_product_access')->insert([
            'id' => (string) Str::ulid(),
            'membership_id' => $membershipId,
            'product' => $product,
            'role' => $role,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
