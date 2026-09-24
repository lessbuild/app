<?php

namespace Tests\Feature\Core;

use App\Core\Enums\ProductKey;
use App\Core\Models\CurrentProductSubscription;
use App\Core\Models\PlatformUser;
use App\Core\Models\ProductSubscription;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceMembershipEvent;
use App\Core\Models\WorkspaceProductAccess;
use App\Core\Services\Workspaces\ManageWorkspaceMembership;
use App\Core\Services\Workspaces\ManageWorkspaceProductAccess;
use App\Modules\Analytics\Models\User as AnalyticsUser;
use App\Modules\Deployer\Models\User as DeployerUser;
use App\Modules\Monitor\Models\User as MonitorUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class WorkspaceProductAccessManagementTest extends TestCase
{
    private PlatformUser $owner;

    private PlatformUser $member;

    private Workspace $workspace;

    private WorkspaceMembership $memberMembership;

    /** @var array<string, string> */
    private array $sourceWorkspaces = [];

    /** @var array<string, string> */
    private array $memberProductUsers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        Artisan::call('platform:migrate', ['module' => 'core']);
        $this->createProductTables();

        $this->owner = $this->createPlatformUser('owner@example.test', 'Workspace Owner');
        $this->member = $this->createPlatformUser('member@example.test', 'Workspace Member');
        $this->workspace = Workspace::query()->create([
            'owner_user_id' => $this->owner->getKey(),
            'name' => 'Signal Workspace',
            'slug' => 'signal-workspace-'.Str::lower(Str::random(5)),
            'status' => 'active',
        ]);
        WorkspaceMembership::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'user_id' => $this->owner->getKey(),
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $this->memberMembership = WorkspaceMembership::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'user_id' => $this->member->getKey(),
            'role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->createProductWorkspacesAndIdentities();
        $this->createCurrentPlans();
        $this->createOwnerProductGrants();
        Auth::forgetGuards();
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();

        foreach ([
            ['analytics', 'workspace_user'],
            ['analytics', 'workspaces'],
            ['analytics', 'users'],
            ['monitor', 'user_workspace'],
            ['monitor', 'workspaces'],
            ['monitor', 'users'],
            ['deployer', 'organization_user'],
            ['deployer', 'organizations'],
            ['deployer', 'users'],
        ] as [$connection, $table]) {
            Schema::connection($connection)->dropIfExists($table);
        }

        if (isset($this->workspace)) {
            DB::connection('core')->table('current_product_subscriptions')
                ->where('workspace_id', $this->workspace->getKey())
                ->delete();
            DB::connection('core')->table('product_subscriptions')
                ->where('workspace_id', $this->workspace->getKey())
                ->delete();
            Workspace::query()->whereKey($this->workspace->getKey())->delete();
        }

        if (isset($this->owner)) {
            PlatformUser::query()->whereKey($this->owner->getKey())->delete();
        }

        if (isset($this->member)) {
            PlatformUser::query()->whereKey($this->member->getKey())->delete();
        }

        parent::tearDown();
    }

    public function test_owner_manages_separate_app_access_roles_and_plan_seats_with_audited_local_membership_projection(): void
    {
        $manager = app(ManageWorkspaceProductAccess::class);

        foreach ([ProductKey::Deployer, ProductKey::Monitor, ProductKey::Analytics] as $product) {
            $this->assertSame(1, $manager->update(
                actor: $this->owner,
                workspace: $this->workspace,
                membership: $this->memberMembership,
                product: $product,
                role: 'member',
            ));
        }

        $this->assertSame('developer', DB::connection('deployer')->table('organization_user')->where('user_id', $this->memberProductUsers['deployer'])->value('role'));
        $this->assertSame('member', DB::connection('monitor')->table('user_workspace')->where('user_id', $this->memberProductUsers['monitor'])->value('role'));
        $this->assertSame('viewer', DB::connection('analytics')->table('workspace_user')->where('user_id', $this->memberProductUsers['analytics'])->value('role'));
        $this->assertSame(3, WorkspaceMembershipEvent::query()->where('event', 'product_access_changed')->count());

        $this->actingAs($this->owner, 'platform')
            ->get(route('core.workspace.team.index', $this->workspace))
            ->assertOk()
            ->assertSeeText('Application access')
            ->assertSeeText('Deployer')
            ->assertSeeText('Monitor')
            ->assertSeeText('Analytics')
            ->assertSeeText('2 of 2 seats in use');

        $this->put(route('core.workspace.team.memberships.products.update', [$this->workspace, $this->memberMembership, 'monitor']), [
            'role' => 'viewer',
        ])->assertRedirect(route('core.workspace.team.index', $this->workspace));
        $this->assertSame('viewer', DB::connection('monitor')->table('user_workspace')->where('user_id', $this->memberProductUsers['monitor'])->value('role'));

        $thirdUser = $this->createPlatformUser('third@example.test', 'Third Member');
        $thirdMembership = WorkspaceMembership::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'user_id' => $thirdUser->getKey(),
            'role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        try {
            $manager->update($this->owner, $this->workspace, $thirdMembership, ProductKey::Monitor, 'member');
            $this->fail('A full Monitor seat pool must reject another app grant.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('role', $exception->errors());
        }
        $this->assertDatabaseMissing('workspace_product_access', [
            'membership_id' => $thirdMembership->getKey(),
            'product' => 'monitor',
        ], 'core');

        $administrator = $this->createPlatformUser('admin@example.test', 'Workspace Admin');
        WorkspaceMembership::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'user_id' => $administrator->getKey(),
            'role' => 'admin',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        try {
            $manager->update($administrator, $this->workspace, $this->memberMembership, ProductKey::Monitor, 'admin');
            $this->fail('Only a workspace owner may assign an app administrator.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertSame(1, $manager->update(
            actor: $this->owner,
            workspace: $this->workspace,
            membership: $this->memberMembership,
            product: ProductKey::Deployer,
            role: null,
        ));
        $this->assertDatabaseHas('workspace_product_access', [
            'membership_id' => $this->memberMembership->getKey(),
            'product' => 'deployer',
            'status' => 'revoked',
        ], 'core');
        $this->assertDatabaseMissing('organization_user', ['user_id' => $this->memberProductUsers['deployer']], 'deployer');
        $this->assertDatabaseHas('workspace_product_access', [
            'membership_id' => $this->memberMembership->getKey(),
            'product' => 'monitor',
            'status' => 'active',
        ], 'core');
        $this->assertDatabaseHas('workspace_product_access', [
            'membership_id' => $this->memberMembership->getKey(),
            'product' => 'analytics',
            'status' => 'active',
        ], 'core');

        $this->assertSame(1, $manager->update(
            actor: $this->owner,
            workspace: $this->workspace,
            membership: $thirdMembership,
            product: ProductKey::Deployer,
            role: 'member',
        ));
        $thirdProductUserId = DB::connection('deployer')->table('users')
            ->where('platform_user_id', $thirdUser->getKey())
            ->value('id');
        $this->assertNotNull($thirdProductUserId);
        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $this->sourceWorkspaces['deployer'],
            'user_id' => $thirdProductUserId,
            'role' => 'developer',
        ], 'deployer');

        $manager->update($this->owner, $this->workspace, $this->memberMembership, ProductKey::Analytics, null);
        $this->assertDatabaseHas('workspace_user', [
            'workspace_id' => $this->sourceWorkspaces['analytics'],
            'user_id' => $this->memberProductUsers['analytics'],
            'role' => 'admin',
        ], 'analytics');

        app(ManageWorkspaceMembership::class)->revoke($this->owner, $this->workspace, $this->memberMembership);
        $this->assertDatabaseMissing('user_workspace', [
            'workspace_id' => $this->sourceWorkspaces['monitor'],
            'user_id' => $this->memberProductUsers['monitor'],
        ], 'monitor');
        $this->assertSame('revoked', DB::connection('core')->table('workspace_product_access')
            ->where('membership_id', $this->memberMembership->getKey())
            ->where('product', 'monitor')
            ->value('status'));
    }

    public function test_shared_workspace_role_cannot_silently_downgrade_an_imported_product_owner(): void
    {
        $manager = app(ManageWorkspaceProductAccess::class);
        $manager->update($this->owner, $this->workspace, $this->memberMembership, ProductKey::Monitor, 'member');

        $grant = WorkspaceProductAccess::query()
            ->where('membership_id', $this->memberMembership->getKey())
            ->where('product', 'monitor')
            ->firstOrFail();
        $grant->forceFill(['role' => 'owner'])->save();
        DB::connection('monitor')->table('user_workspace')
            ->where('workspace_id', $this->sourceWorkspaces['monitor'])
            ->where('user_id', $this->memberProductUsers['monitor'])
            ->update(['role' => 'owner']);

        $this->actingAs($this->owner, 'platform')
            ->get(route('core.workspace.team.index', $this->workspace))
            ->assertOk()
            ->assertSeeText('Owner access')
            ->assertSeeText('Revoke access');

        try {
            $manager->update($this->owner, $this->workspace, $this->memberMembership, ProductKey::Monitor, 'member');
            $this->fail('A shared workspace role change must not silently demote an imported product owner.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('role', $exception->errors());
        }

        $this->assertSame('owner', $grant->fresh()->role);
        $manager->update($this->owner, $this->workspace, $this->memberMembership, ProductKey::Monitor, null);

        $this->assertSame('revoked', $grant->fresh()->status);
        $this->assertSame('owner', DB::connection('monitor')->table('user_workspace')
            ->where('workspace_id', $this->sourceWorkspaces['monitor'])
            ->where('user_id', $this->memberProductUsers['monitor'])
            ->value('role'));
    }

    public function test_product_access_revocation_still_blocks_core_access_when_local_identity_cleanup_needs_review(): void
    {
        $manager = app(ManageWorkspaceProductAccess::class);
        $manager->update($this->owner, $this->workspace, $this->memberMembership, ProductKey::Monitor, 'member');

        DB::connection('core')->table('legacy_identity_maps')
            ->where('source_product', 'monitor')
            ->where('source_entity', 'user')
            ->where('source_id', $this->memberProductUsers['monitor'])
            ->delete();

        $manager->update($this->owner, $this->workspace, $this->memberMembership, ProductKey::Monitor, null);

        $grant = WorkspaceProductAccess::query()
            ->where('membership_id', $this->memberMembership->getKey())
            ->where('product', 'monitor')
            ->firstOrFail();
        $this->assertSame('revoked', $grant->status);
        $this->assertTrue($grant->metadata['local_membership_cleanup_pending']);
        $this->assertNotEmpty($grant->metadata['managed_product_memberships']);
        $this->assertDatabaseHas('user_workspace', [
            'workspace_id' => $this->sourceWorkspaces['monitor'],
            'user_id' => $this->memberProductUsers['monitor'],
        ], 'monitor');
        $this->assertTrue(WorkspaceMembershipEvent::query()
            ->where('event', 'product_access_changed')
            ->whereNull('new_role')
            ->firstOrFail()
            ->metadata['local_membership_cleanup_pending']);

        $this->identityMap('monitor', 'user', $this->memberProductUsers['monitor'], 'user', (string) $this->member->getKey());
        $manager->update($this->owner, $this->workspace, $this->memberMembership, ProductKey::Monitor, null);

        $this->assertDatabaseMissing('user_workspace', [
            'workspace_id' => $this->sourceWorkspaces['monitor'],
            'user_id' => $this->memberProductUsers['monitor'],
        ], 'monitor');
        $this->assertFalse($grant->fresh()->metadata['local_membership_cleanup_pending']);
        $this->assertSame('product_access_cleanup_completed', WorkspaceMembershipEvent::query()
            ->where('event', 'product_access_cleanup_completed')
            ->value('event'));
    }

    private function createProductTables(): void
    {
        foreach (['deployer', 'monitor', 'analytics'] as $connection) {
            Schema::connection($connection)->create('users', function (Blueprint $table): void {
                $table->id();
                $table->ulid('platform_user_id')->nullable()->unique();
                $table->string('name');
                $table->string('email')->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password')->nullable();
                $table->string('auth_type')->nullable();
                $table->timestamp('password_set_at')->nullable();
                $table->unsignedBigInteger('current_organization_id')->nullable();
                $table->timestamps();
            });
        }

        Schema::connection('deployer')->create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('owner_id');
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });
        Schema::connection('deployer')->create('organization_user', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role');
            $table->timestamps();
            $table->primary(['organization_id', 'user_id']);
        });

        Schema::connection('monitor')->create('workspaces', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('owner_id');
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });
        Schema::connection('monitor')->create('user_workspace', function (Blueprint $table): void {
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role');
            $table->timestamps();
            $table->primary(['user_id', 'workspace_id']);
        });

        Schema::connection('analytics')->create('workspaces', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });
        Schema::connection('analytics')->create('workspace_user', function (Blueprint $table): void {
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role');
            $table->timestamps();
            $table->primary(['user_id', 'workspace_id']);
        });
    }

    private function createProductWorkspacesAndIdentities(): void
    {
        $definitions = [
            'deployer' => [
                'user_model' => DeployerUser::class,
                'workspace_table' => 'organizations',
                'entity' => 'organization',
                'pivot' => 'organization_user',
                'pivot_workspace' => 'organization_id',
            ],
            'monitor' => [
                'user_model' => MonitorUser::class,
                'workspace_table' => 'workspaces',
                'entity' => 'workspace',
                'pivot' => 'user_workspace',
                'pivot_workspace' => 'workspace_id',
            ],
            'analytics' => [
                'user_model' => AnalyticsUser::class,
                'workspace_table' => 'workspaces',
                'entity' => 'workspace',
                'pivot' => 'workspace_user',
                'pivot_workspace' => 'workspace_id',
            ],
        ];

        foreach ($definitions as $product => $definition) {
            $localOwnerId = DB::connection($product)->table('users')->insertGetId([
                'name' => 'Legacy Owner',
                'email' => $product.'-owner@example.test',
                'platform_user_id' => $this->owner->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $localMemberId = DB::connection($product)->table('users')->insertGetId([
                'name' => $this->member->name,
                'email' => $product.'-member@example.test',
                'platform_user_id' => $this->member->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->memberProductUsers[$product] = (string) $localMemberId;

            $attributes = [
                'owner_id' => $localOwnerId,
                'name' => $product.' workspace',
                'slug' => $product.'-workspace',
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if ($product === 'analytics') {
                unset($attributes['owner_id']);
            }
            $localWorkspaceId = DB::connection($product)->table($definition['workspace_table'])->insertGetId($attributes);
            $this->sourceWorkspaces[$product] = (string) $localWorkspaceId;
            DB::connection($product)->table($definition['pivot'])->insert([
                $definition['pivot_workspace'] => $localWorkspaceId,
                'user_id' => $localOwnerId,
                'role' => 'owner',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($product === 'analytics') {
                DB::connection($product)->table($definition['pivot'])->insert([
                    $definition['pivot_workspace'] => $localWorkspaceId,
                    'user_id' => $localMemberId,
                    'role' => 'admin',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->identityMap($product, 'user', $localOwnerId, 'user', (string) $this->owner->getKey());
            $this->identityMap($product, 'user', $localMemberId, 'user', (string) $this->member->getKey());
            $this->identityMap($product, $definition['entity'], $localWorkspaceId, 'workspace', (string) $this->workspace->getKey());
        }
    }

    private function createCurrentPlans(): void
    {
        foreach ([
            'deployer' => ['members' => 2],
            'monitor' => ['seats' => 2],
            'analytics' => ['members' => 2],
        ] as $product => $limits) {
            $subscription = ProductSubscription::query()->create([
                'workspace_id' => $this->workspace->getKey(),
                'product' => $product,
                'provider' => 'test',
                'plan_key' => 'team',
                'status' => 'active',
                'quantity' => 1,
                'metadata' => ['plan_snapshot' => [
                    'name' => 'Team',
                    'entitlements' => ['*'],
                    'limits' => $limits,
                ]],
            ]);
            CurrentProductSubscription::query()->create([
                'workspace_id' => $this->workspace->getKey(),
                'product' => $product,
                'product_subscription_id' => $subscription->getKey(),
            ]);
        }
    }

    private function createOwnerProductGrants(): void
    {
        $ownerMembership = WorkspaceMembership::query()
            ->where('workspace_id', $this->workspace->getKey())
            ->where('user_id', $this->owner->getKey())
            ->firstOrFail();

        foreach (['deployer', 'monitor', 'analytics'] as $product) {
            WorkspaceProductAccess::query()->create([
                'membership_id' => $ownerMembership->getKey(),
                'product' => $product,
                'role' => 'owner',
                'status' => 'active',
                'granted_at' => now(),
            ]);
        }
    }

    private function identityMap(string $product, string $entity, int|string $sourceId, string $canonicalEntity, string $canonicalId): void
    {
        DB::connection('core')->table('legacy_identity_maps')->insert([
            'id' => (string) Str::ulid(),
            'source_product' => $product,
            'source_entity' => $entity,
            'source_id' => (string) $sourceId,
            'canonical_entity' => $canonicalEntity,
            'canonical_id' => $canonicalId,
            'status' => 'reconciled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPlatformUser(string $email, string $name): PlatformUser
    {
        return PlatformUser::query()->forceCreate([
            'id' => (string) Str::ulid(),
            'name' => $name,
            'email' => $email,
            'email_normalized' => $email,
            'password' => 'already-hashed',
            'status' => 'active',
        ]);
    }
}
