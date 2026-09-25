<?php

namespace Tests\Feature\Core;

use App\Core\Models\PlatformUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WorkspaceFeedbackHistoryTest extends TestCase
{
    private string $userId;

    private string $workspaceId;

    private string $membershipId;

    protected function setUp(): void
    {
        parent::setUp();
        URL::forceRootUrl('http://localhost');
        $this->createTables();

        $this->userId = (string) Str::ulid();
        $this->workspaceId = (string) Str::ulid();
        $this->membershipId = (string) Str::ulid();
        $this->insertCoreUser($this->userId, 'Workspace Owner');
        DB::connection('core')->table('workspaces')->insert([
            'id' => $this->workspaceId,
            'owner_user_id' => $this->userId,
            'name' => 'Northstar Studio',
            'slug' => 'northstar-studio',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('workspace_memberships')->insert([
            'id' => $this->membershipId,
            'workspace_id' => $this->workspaceId,
            'user_id' => $this->userId,
            'role' => 'owner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('workspace_product_access')->insert([
            'id' => (string) Str::ulid(),
            'membership_id' => $this->membershipId,
            'product' => 'deployer',
            'role' => 'owner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        config(['platform.products.deployer.url' => 'http://localhost']);
        Auth::forgetGuards();
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        URL::forceRootUrl(null);

        foreach (['product_feedback', 'organization_user', 'organizations', 'users'] as $table) {
            Schema::connection('deployer')->dropIfExists($table);
        }
        foreach (['workspace_feedback', 'legacy_identity_maps', 'workspace_product_access', 'workspace_memberships', 'workspaces', 'users'] as $table) {
            Schema::connection('core')->dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_workspace_manager_can_view_deployer_history_without_copying_or_rewriting_its_encrypted_records(): void
    {
        $this->seedLegacyOrganizationAndFeedback();
        $before = DB::connection('deployer')->table('product_feedback')->where('id', 501)->value('description');
        $user = PlatformUser::query()->findOrFail($this->userId);

        $this->actingAs($user, 'platform')
            ->get(route('core.workspace.feedback.index', $this->workspaceId))
            ->assertOk()
            ->assertSeeText('Older Deployer feedback')
            ->assertSeeText('A historical report with private details.')
            ->assertSeeText('Read only')
            ->assertSee('http://localhost/feedback?organization_id=700');

        $this->assertSame($before, DB::connection('deployer')->table('product_feedback')->where('id', 501)->value('description'));
        $this->assertSame(0, DB::connection('core')->table('workspace_feedback')->count());
    }

    public function test_non_reviewers_only_see_their_own_legacy_deployer_submissions(): void
    {
        $this->seedLegacyOrganizationAndFeedback();
        $otherUserId = 802;
        DB::connection('deployer')->table('users')->insert([
            'id' => $otherUserId,
            'name' => 'Other Deployer member',
            'email' => 'other@example.test',
            'password' => 'legacy-hash',
            'current_organization_id' => 700,
        ]);
        DB::connection('deployer')->table('organizations')->where('id', 700)->update(['owner_id' => $otherUserId]);
        DB::connection('deployer')->table('organization_user')->insert([
            'organization_id' => 700,
            'user_id' => $this->legacyUserId(),
            'role' => 'developer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('deployer')->table('organization_user')->insert([
            'organization_id' => 700,
            'user_id' => $otherUserId,
            'role' => 'developer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('deployer')->table('product_feedback')->insert([
            'id' => 502,
            'organization_id' => 700,
            'user_id' => $otherUserId,
            'category' => 'bug',
            'severity' => 'normal',
            'status' => 'open',
            'title' => 'Another member private item',
            'description' => Crypt::encryptString('Only another member should see this.'),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        DB::connection('core')->table('workspace_memberships')->where('id', $this->membershipId)->update(['role' => 'viewer']);
        DB::connection('core')->table('workspace_product_access')->where('membership_id', $this->membershipId)->update(['role' => 'member']);
        $user = PlatformUser::query()->findOrFail($this->userId);

        $this->actingAs($user, 'platform')
            ->get(route('core.workspace.feedback.index', $this->workspaceId))
            ->assertOk()
            ->assertSeeText('A historical report with private details.')
            ->assertDontSeeText('Another member private item')
            ->assertDontSeeText('Only another member should see this.');
    }

    public function test_unreadable_encrypted_history_fails_closed_without_rendering_partial_private_content(): void
    {
        $this->seedLegacyOrganizationAndFeedback();
        DB::connection('deployer')->table('product_feedback')->where('id', 501)->update([
            'description' => 'invalid-encrypted-payload',
        ]);
        $user = PlatformUser::query()->findOrFail($this->userId);

        $this->actingAs($user, 'platform')
            ->get(route('core.workspace.feedback.index', $this->workspaceId))
            ->assertOk()
            ->assertSeeText('Older Deployer feedback could not be loaded')
            ->assertDontSeeText('A historical report with private details.');
    }

    public function test_legacy_history_does_not_render_a_deployer_link_for_an_unverified_origin(): void
    {
        $this->seedLegacyOrganizationAndFeedback();
        config(['platform.products.deployer.url' => 'https://deployer.example.test']);
        $user = PlatformUser::query()->findOrFail($this->userId);

        $this->actingAs($user, 'platform')
            ->get(route('core.workspace.feedback.index', $this->workspaceId))
            ->assertOk()
            ->assertSeeText('A historical report with private details.')
            ->assertDontSee('organization_id=700');
    }

    private function createTables(): void
    {
        foreach (['workspace_feedback', 'legacy_identity_maps', 'workspace_product_access', 'workspace_memberships', 'workspaces', 'users'] as $table) {
            Schema::connection('core')->dropIfExists($table);
        }
        foreach (['product_feedback', 'organization_user', 'organizations', 'users'] as $table) {
            Schema::connection('deployer')->dropIfExists($table);
        }

        Schema::connection('core')->create('users', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('email_normalized')->nullable();
            $table->string('password')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });
        Schema::connection('core')->create('workspaces', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('owner_user_id', 26)->nullable();
            $table->string('name');
            $table->string('slug');
            $table->string('status')->default('active');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('workspace_memberships', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('user_id', 26);
            $table->string('role');
            $table->string('status')->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('workspace_product_access', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('membership_id', 26);
            $table->string('product', 24);
            $table->string('role', 24)->default('member');
            $table->string('status')->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('legacy_identity_maps', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('source_product');
            $table->string('source_entity');
            $table->string('source_id');
            $table->string('canonical_entity')->nullable();
            $table->string('canonical_id', 26)->nullable();
            $table->string('status');
        });
        Schema::connection('core')->create('workspace_feedback', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('user_id', 26);
            $table->char('reviewed_by_user_id', 26)->nullable();
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
        Schema::connection('deployer')->create('users', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->unsignedBigInteger('current_organization_id')->nullable();
        });
        Schema::connection('deployer')->create('organizations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name');
            $table->unsignedBigInteger('owner_id')->nullable();
        });
        Schema::connection('deployer')->create('organization_user', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role');
            $table->timestamps();
        });
        Schema::connection('deployer')->create('product_feedback', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->string('category', 20);
            $table->string('severity', 20);
            $table->string('status', 20)->default('open');
            $table->string('title', 160);
            $table->text('description');
            $table->text('reproduction_steps')->nullable();
            $table->text('review_response')->nullable();
            $table->string('page', 500)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    private function seedLegacyOrganizationAndFeedback(): void
    {
        DB::connection('deployer')->table('users')->insert([
            'id' => $this->legacyUserId(),
            'name' => 'Workspace Owner',
            'email' => 'owner@example.test',
            'password' => 'legacy-hash',
            'current_organization_id' => 700,
        ]);
        DB::connection('deployer')->table('organizations')->insert([
            'id' => 700,
            'name' => 'Northstar Legacy Organization',
            'owner_id' => $this->legacyUserId(),
        ]);
        DB::connection('core')->table('legacy_identity_maps')->insert([
            ['id' => (string) Str::ulid(), 'source_product' => 'deployer', 'source_entity' => 'organization', 'source_id' => '700', 'canonical_entity' => 'workspace', 'canonical_id' => $this->workspaceId, 'status' => 'reconciled'],
            ['id' => (string) Str::ulid(), 'source_product' => 'deployer', 'source_entity' => 'user', 'source_id' => (string) $this->legacyUserId(), 'canonical_entity' => 'user', 'canonical_id' => $this->userId, 'status' => 'reconciled'],
        ]);
        DB::connection('deployer')->table('product_feedback')->insert([
            'id' => 501,
            'organization_id' => 700,
            'user_id' => $this->legacyUserId(),
            'category' => 'bug',
            'severity' => 'high',
            'status' => 'reviewing',
            'title' => 'Historical private report',
            'description' => Crypt::encryptString('A historical report with private details.'),
            'reproduction_steps' => Crypt::encryptString('Open the deployment history.'),
            'review_response' => null,
            'page' => '/projects',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertCoreUser(string $id, string $name): void
    {
        DB::connection('core')->table('users')->insert([
            'id' => $id,
            'name' => $name,
            'email' => 'owner@example.test',
            'email_normalized' => 'owner@example.test',
            'password' => 'hashed-password',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function legacyUserId(): int
    {
        return 801;
    }
}
