<?php

namespace Tests\Feature\Core;

use App\Core\Contracts\WorkspaceMonitorStatusManagementProvider;
use App\Core\Data\Status\WorkspaceMonitorStatusManagement;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Services\WorkspaceMonitorStatusManagementProviderRegistry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WorkspaceMonitorStatusPagesTest extends TestCase
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
        DB::connection('core')->table('users')->insert([
            'id' => $this->userId,
            'name' => 'Monitor Workspace Owner',
            'email' => 'monitor-owner@example.test',
            'email_normalized' => 'monitor-owner@example.test',
            'password' => 'hashed-password',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('workspaces')->insert([
            'id' => $this->workspaceId,
            'owner_user_id' => $this->userId,
            'name' => 'Monitor Workspace',
            'slug' => 'monitor-workspace',
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
            'product' => 'monitor',
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

    public function test_core_monitor_status_screen_uses_signal_and_delegates_changes_to_its_provider(): void
    {
        $provider = new class implements WorkspaceMonitorStatusManagementProvider
        {
            public int $updates = 0;

            /** @var array<string, mixed> */
            public array $attributes = [];

            public function forWorkspace(PlatformUser $user, Workspace $workspace): ?WorkspaceMonitorStatusManagement
            {
                return new WorkspaceMonitorStatusManagement(
                    monitors: [[
                        'id' => '41',
                        'name' => 'Public API',
                        'type' => 'HTTP uptime',
                        'application' => 'Storefront',
                        'environment' => 'production',
                        'health' => 'Up',
                    ]],
                    pages: [[
                        'id' => '17',
                        'name' => 'Acme Status',
                        'slug' => 'acme-status',
                        'description' => 'Service health for Acme customers.',
                        'published' => true,
                        'monitor_ids' => ['41'],
                        'component_names' => ['Public API'],
                        'public_url' => 'http://localhost/status/monitor/acme-status',
                    ]],
                    canManage: true,
                );
            }

            public function create(PlatformUser $user, Workspace $workspace, array $attributes): bool
            {
                return true;
            }

            public function update(PlatformUser $user, Workspace $workspace, string $pageId, array $attributes): bool
            {
                $this->updates++;
                $this->attributes = $attributes;

                return $pageId === '17';
            }

            public function delete(PlatformUser $user, Workspace $workspace, string $pageId): bool
            {
                return $pageId === '17';
            }
        };
        app(WorkspaceMonitorStatusManagementProviderRegistry::class)->register('monitor', $provider);
        $user = PlatformUser::query()->findOrFail($this->userId);

        $this->actingAs($user, 'platform')
            ->get(route('core.workspace.monitor-status-pages.index', $this->workspaceId))
            ->assertOk()
            ->assertSeeText('Monitor status pages')
            ->assertSeeText('Public API')
            ->assertSeeText('Service health for Acme customers.')
            ->assertDontSeeText('private-target.internal')
            ->assertSee(route('core.workspace.admin', $this->workspaceId));

        $this->actingAs($user, 'platform')
            ->patch(route('core.workspace.monitor-status-pages.update', [$this->workspaceId, 17]), [
                'name' => 'Acme Production Status',
                'slug' => 'acme-production-status',
                'description' => 'Production health for Acme customers.',
                'published' => '1',
                'monitor_ids' => ['41'],
            ])
            ->assertRedirect(route('core.workspace.monitor-status-pages.index', $this->workspaceId));

        $this->assertSame(1, $provider->updates);
        $this->assertSame('acme-production-status', $provider->attributes['slug']);
        $this->assertSame(['41'], $provider->attributes['monitor_ids']);
    }

    public function test_core_monitor_status_routes_require_an_active_product_grant(): void
    {
        DB::connection('core')->table('workspace_product_access')->delete();
        $provider = new class implements WorkspaceMonitorStatusManagementProvider
        {
            public int $reads = 0;

            public function forWorkspace(PlatformUser $user, Workspace $workspace): ?WorkspaceMonitorStatusManagement
            {
                $this->reads++;

                return null;
            }

            public function create(PlatformUser $user, Workspace $workspace, array $attributes): bool
            {
                return false;
            }

            public function update(PlatformUser $user, Workspace $workspace, string $pageId, array $attributes): bool
            {
                return false;
            }

            public function delete(PlatformUser $user, Workspace $workspace, string $pageId): bool
            {
                return false;
            }
        };
        app(WorkspaceMonitorStatusManagementProviderRegistry::class)->register('monitor', $provider);
        $user = PlatformUser::query()->findOrFail($this->userId);

        $this->actingAs($user, 'platform')
            ->get(route('core.workspace.monitor-status-pages.index', $this->workspaceId))
            ->assertNotFound();

        $this->assertSame(0, $provider->reads);
    }

    private function createTables(): void
    {
        foreach (['workspace_product_access', 'workspace_memberships', 'workspaces', 'users'] as $table) {
            Schema::connection('core')->dropIfExists($table);
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
    }
}
