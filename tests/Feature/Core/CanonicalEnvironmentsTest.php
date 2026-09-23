<?php

namespace Tests\Feature\Core;

use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\Workspace;
use App\Core\Services\Projects\CreateCanonicalEnvironment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CanonicalEnvironmentsTest extends TestCase
{
    private string $userId;

    private string $workspaceId;

    private string $projectId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::connection('core')->create('workspaces', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('owner_user_id', 26);
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
            $table->string('role', 24);
            $table->string('status', 24);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('projects', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('created_by_user_id', 26)->nullable();
            $table->string('name');
            $table->string('slug');
            $table->string('status')->default('active');
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('project_memberships', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('project_id', 26);
            $table->char('user_id', 26);
            $table->string('role', 24);
            $table->string('status', 24);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('project_environments', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('project_id', 26);
            $table->char('created_by_user_id', 26)->nullable();
            $table->string('name');
            $table->string('slug', 120);
            $table->string('environment_type', 32)->default('custom');
            $table->string('status', 24)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'slug']);
        });
        Schema::connection('core')->create('project_products', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('project_id', 26);
            $table->string('product', 24);
            $table->string('status', 24);
            $table->char('requested_by_user_id', 26)->nullable();
            $table->timestamps();
        });

        $this->userId = (string) Str::ulid();
        $this->workspaceId = (string) Str::ulid();
        $this->projectId = (string) Str::ulid();
        DB::connection('core')->table('workspaces')->insert([
            'id' => $this->workspaceId,
            'owner_user_id' => $this->userId,
            'name' => 'Platform team',
            'slug' => 'platform-team',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('workspace_memberships')->insert([
            'id' => (string) Str::ulid(),
            'workspace_id' => $this->workspaceId,
            'user_id' => $this->userId,
            'role' => 'owner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('projects')->insert([
            'id' => $this->projectId,
            'workspace_id' => $this->workspaceId,
            'created_by_user_id' => $this->userId,
            'name' => 'Checkout',
            'slug' => 'checkout',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('project_memberships')->insert([
            'id' => (string) Str::ulid(),
            'project_id' => $this->projectId,
            'user_id' => $this->userId,
            'role' => 'owner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['project_products', 'project_environments', 'project_memberships', 'projects', 'workspace_memberships', 'workspaces'] as $table) {
            Schema::connection('core')->dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_workspace_owner_can_create_distinct_canonical_environments_without_linking_an_app(): void
    {
        $workspace = Workspace::query()->findOrFail($this->workspaceId);
        $project = Project::query()->findOrFail($this->projectId);
        $user = (new PlatformUser)->forceFill(['id' => $this->userId]);
        $createEnvironment = app(CreateCanonicalEnvironment::class);

        $first = $createEnvironment->handle($workspace, $project, $user, 'Pre production', 'staging');
        $second = $createEnvironment->handle($workspace, $project, $user, 'Pre production', 'custom');

        $this->assertSame('pre-production', $first->slug);
        $this->assertSame('pre-production-2', $second->slug);
        $this->assertSame('staging', $first->environment_type);
        $this->assertSame('custom', $second->environment_type);
        $this->assertSame('active', $first->status);
        $this->assertSame($this->userId, $first->created_by_user_id);
        $this->assertSame(2, DB::connection('core')->table('project_environments')->count());
        $this->assertDatabaseCount('project_products', 0, 'core');
    }

    public function test_project_member_without_workspace_management_cannot_create_shared_environments(): void
    {
        DB::connection('core')->table('workspace_memberships')->where('user_id', $this->userId)->update(['role' => 'member']);
        $workspace = Workspace::query()->findOrFail($this->workspaceId);
        $project = Project::query()->findOrFail($this->projectId);
        $user = (new PlatformUser)->forceFill(['id' => $this->userId]);

        try {
            app(CreateCanonicalEnvironment::class)->handle($workspace, $project, $user, 'Production', 'production');
            $this->fail('A workspace member cannot create shared project environments.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('project_environments', 0, 'core');
        }
    }
}
