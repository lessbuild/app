<?php

namespace Tests\Feature\Core;

use App\Core\Models\PlatformUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WorkspaceDirectoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::connection('core')->create('users', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('email_normalized')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->string('status', 24)->default('active');
            $table->timestamps();
        });
        Schema::connection('core')->create('workspaces', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('owner_user_id', 26);
            $table->string('name');
            $table->string('slug', 120)->unique();
            $table->string('status', 24)->default('active');
            $table->json('settings')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('workspace_memberships', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('user_id', 26);
            $table->string('role', 32);
            $table->string('status', 24);
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id']);
        });
        Schema::connection('core')->create('workspace_product_access', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('membership_id', 26);
            $table->string('product', 24);
            $table->string('role', 32);
            $table->string('status', 24);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::connection('core')->dropIfExists('workspace_product_access');
        Schema::connection('core')->dropIfExists('workspace_memberships');
        Schema::connection('core')->dropIfExists('workspaces');
        Schema::connection('core')->dropIfExists('users');

        parent::tearDown();
    }

    public function test_verified_account_can_create_a_shared_workspace_owned_in_core(): void
    {
        $user = PlatformUser::query()->forceCreate([
            'id' => (string) Str::ulid(),
            'name' => 'Taylor Example',
            'email' => 'taylor@example.test',
            'email_normalized' => 'taylor@example.test',
            'email_verified_at' => now(),
            'status' => 'active',
        ]);

        $this->actingAs($user, 'platform')
            ->from(route('core.workspaces.index'))
            ->post(route('core.workspaces.store'), ['name' => '  Product Studio  '])
            ->assertRedirect();

        $workspace = DB::connection('core')->table('workspaces')->sole();
        $membership = DB::connection('core')->table('workspace_memberships')->sole();

        $this->assertSame('Product Studio', $workspace->name);
        $this->assertSame($user->getKey(), $workspace->owner_user_id);
        $this->assertSame('owner', $membership->role);
        $this->assertSame($workspace->id, $membership->workspace_id);
        $this->assertSame(0, DB::connection('core')->table('workspace_product_access')->count());
    }

    public function test_unverified_account_cannot_create_a_shared_workspace(): void
    {
        $user = PlatformUser::query()->forceCreate([
            'id' => (string) Str::ulid(),
            'name' => 'Unverified Example',
            'email' => 'unverified@example.test',
            'email_normalized' => 'unverified@example.test',
            'status' => 'active',
        ]);

        $this->actingAs($user, 'platform')
            ->post(route('core.workspaces.store'), ['name' => 'Blocked workspace'])
            ->assertForbidden();

        $this->assertSame(0, DB::connection('core')->table('workspaces')->count());
    }
}
