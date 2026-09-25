<?php

namespace Tests\Feature\Core;

use App\Core\Models\PlatformUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WorkspaceAdministrationTest extends TestCase
{
    private string $userId;

    private string $workspaceId;

    private string $membershipId;

    private string $deployerAccessId;

    protected function setUp(): void
    {
        parent::setUp();
        URL::forceRootUrl('http://localhost');
        $this->createTables();

        $this->userId = (string) Str::ulid();
        $this->workspaceId = (string) Str::ulid();
        $this->membershipId = (string) Str::ulid();
        $this->deployerAccessId = (string) Str::ulid();

        DB::connection('core')->table('users')->insert([
            'id' => $this->userId,
            'name' => 'Alex Owner',
            'email' => 'alex@example.test',
            'email_normalized' => 'alex@example.test',
            'password' => 'hashed-password',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
            'id' => $this->deployerAccessId,
            'membership_id' => $this->membershipId,
            'product' => 'deployer',
            'role' => 'owner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Auth::forgetGuards();
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        URL::forceRootUrl(null);

        foreach (['workspace_product_access', 'workspace_memberships', 'workspaces', 'users'] as $table) {
            Schema::connection('core')->dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_admin_catalog_covers_deployer_feature_areas_through_trusted_product_links(): void
    {
        config(['platform.products.deployer.url' => 'http://localhost']);
        $user = PlatformUser::query()->findOrFail($this->userId);

        $this->actingAs($user, 'platform')
            ->get(route('core.workspace.admin', $this->workspaceId))
            ->assertOk()
            ->assertSeeText('Organization settings')
            ->assertSeeText('Server fleet and imports')
            ->assertSeeText('Websites, imports, and checks')
            ->assertSeeText('Backups and recovery')
            ->assertSeeText('Costs, estimates, and budgets')
            ->assertSeeText('Notification center')
            ->assertSeeText('Feedback review')
            ->assertSee(route('servers.index'))
            ->assertSee(route('backups.index'))
            ->assertSee(route('costs.index'))
            ->assertSee('data-search="team access members roles invitations grants seats', false)
            ->assertSee('data-search="server fleet and imports deployer review servers', false)
            ->assertSee('data-search="backups and recovery deployer manage backup destinations', false);
    }

    public function test_admin_catalog_hides_product_controls_after_the_product_grant_is_revoked(): void
    {
        config(['platform.products.deployer.url' => 'http://localhost']);
        $user = PlatformUser::query()->findOrFail($this->userId);
        $this->actingAs($user, 'platform')
            ->get(route('core.workspace.admin', $this->workspaceId))
            ->assertSeeText('Server fleet and imports');

        DB::connection('core')->table('workspace_product_access')
            ->where('id', $this->deployerAccessId)
            ->update(['revoked_at' => now()]);

        $this->get(route('core.workspace.admin', $this->workspaceId))
            ->assertOk()
            ->assertDontSeeText('App administration')
            ->assertDontSeeText('Server fleet and imports')
            ->assertDontSee('data-search="server fleet and imports deployer', false);
    }

    public function test_admin_catalog_fails_closed_when_the_configured_product_origin_disagrees_with_its_route(): void
    {
        config(['platform.products.deployer.url' => 'https://deployer.example.test']);
        $user = PlatformUser::query()->findOrFail($this->userId);

        $this->actingAs($user, 'platform')
            ->get(route('core.workspace.admin', $this->workspaceId))
            ->assertOk()
            ->assertDontSeeText('App administration')
            ->assertDontSeeText('Server fleet and imports');
    }

    private function createTables(): void
    {
        Schema::connection('core')->create('users', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('email_normalized')->nullable();
            $table->string('password')->nullable();
            $table->string('status', 24)->default('active');
            $table->timestamps();
        });
        Schema::connection('core')->create('workspaces', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('owner_user_id', 26)->nullable();
            $table->string('name');
            $table->string('slug');
            $table->string('status', 24)->default('active');
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
        Schema::connection('core')->create('workspace_product_access', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('membership_id', 26);
            $table->string('product', 24);
            $table->string('role', 24);
            $table->string('status', 24);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }
}
