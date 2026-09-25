<?php

namespace Tests\Feature\Monitor;

use App\Core\Models\PlatformUser;
use App\Core\Services\Auth\ProductAuthentication;
use App\Core\Services\Identity\ProductWorkspaceAccess;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Services\CurrentWorkspace;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class CurrentWorkspaceContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::connection('monitor')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->timestamps();
        });
        Schema::connection('monitor')->create('workspaces', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('owner_id');
            $table->string('name');
            $table->string('slug');
            $table->string('plan')->default('free');
            $table->timestamps();
        });
        Schema::connection('monitor')->create('user_workspace', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role')->default('member');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::connection('monitor')->dropIfExists('user_workspace');
        Schema::connection('monitor')->dropIfExists('workspaces');
        Schema::connection('monitor')->dropIfExists('users');

        parent::tearDown();
    }

    public function test_search_result_workspace_context_is_selected_only_when_the_user_is_a_member(): void
    {
        $userId = DB::connection('monitor')->table('users')->insertGetId([
            'name' => 'Monitor member',
            'email' => 'monitor-member@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $workspaceId = DB::connection('monitor')->table('workspaces')->insertGetId([
            'owner_id' => $userId,
            'name' => 'Search workspace',
            'slug' => 'search-workspace',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('monitor')->table('user_workspace')->insert([
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'role' => 'owner',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::query()->findOrFail($userId);
        $request = $this->requestFor($user, $workspaceId);
        $workspace = (new CurrentWorkspace($request, app(ProductAuthentication::class), app(ProductWorkspaceAccess::class)))->get();

        $this->assertSame($workspaceId, $workspace->getKey());
        $this->assertSame($workspaceId, $request->session()->get('workspace_id'));
    }

    public function test_search_result_cannot_select_another_users_workspace(): void
    {
        $userId = DB::connection('monitor')->table('users')->insertGetId([
            'name' => 'Monitor member',
            'email' => 'monitor-member@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $workspaceId = DB::connection('monitor')->table('workspaces')->insertGetId([
            'owner_id' => $userId + 1,
            'name' => 'Private workspace',
            'slug' => 'private-workspace',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            (new CurrentWorkspace($this->requestFor(User::query()->findOrFail($userId), $workspaceId), app(ProductAuthentication::class), app(ProductWorkspaceAccess::class)))->get();
            $this->fail('An unauthorized workspace must not become the active Monitor context.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_core_authority_requires_an_active_product_grant_for_the_mapped_workspace(): void
    {
        Artisan::call('platform:migrate', ['module' => 'core']);
        config(['platform.products.monitor.auth_authority' => 'core']);

        $platformUser = PlatformUser::query()->forceCreate([
            'id' => (string) Str::ulid(),
            'name' => 'Core member',
            'email' => 'core-member@example.test',
            'email_normalized' => 'core-member@example.test',
            'password' => 'hashed-password',
            'status' => 'active',
        ]);
        $canonicalWorkspaceId = (string) Str::ulid();
        $membershipId = (string) Str::ulid();
        DB::connection('core')->table('workspaces')->insert([
            'id' => $canonicalWorkspaceId,
            'owner_user_id' => $platformUser->getKey(),
            'name' => 'Authorized Monitor workspace',
            'slug' => 'authorized-monitor-workspace',
            'status' => 'active',
            'settings' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('workspace_memberships')->insert([
            'id' => $membershipId,
            'workspace_id' => $canonicalWorkspaceId,
            'user_id' => $platformUser->getKey(),
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $grantId = (string) Str::ulid();
        DB::connection('core')->table('workspace_product_access')->insert([
            'id' => $grantId,
            'membership_id' => $membershipId,
            'product' => 'monitor',
            'role' => 'owner',
            'status' => 'active',
            'granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productUserId = DB::connection('monitor')->table('users')->insertGetId([
            'name' => 'Monitor member',
            'email' => 'monitor-member@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sourceWorkspaceId = DB::connection('monitor')->table('workspaces')->insertGetId([
            'owner_id' => $productUserId,
            'name' => 'Authorized Monitor workspace',
            'slug' => 'authorized-monitor-workspace',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('monitor')->table('user_workspace')->insert([
            'workspace_id' => $sourceWorkspaceId,
            'user_id' => $productUserId,
            'role' => 'owner',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('legacy_identity_maps')->insert([
            'id' => (string) Str::ulid(),
            'source_product' => 'monitor',
            'source_entity' => 'workspace',
            'source_id' => (string) $sourceWorkspaceId,
            'canonical_entity' => 'workspace',
            'canonical_id' => $canonicalWorkspaceId,
            'status' => 'reconciled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $request = $this->requestFor(User::query()->findOrFail($productUserId), $sourceWorkspaceId);
        $request->attributes->set('platform_user', $platformUser);
        $workspace = (new CurrentWorkspace(
            $request,
            app(ProductAuthentication::class),
            app(ProductWorkspaceAccess::class),
        ))->get();
        $this->assertSame($sourceWorkspaceId, $workspace->getKey());

        DB::connection('core')->table('workspace_product_access')->where('id', $grantId)->update([
            'status' => 'revoked',
            'revoked_at' => now(),
        ]);

        try {
            (new CurrentWorkspace(
                $request,
                app(ProductAuthentication::class),
                app(ProductWorkspaceAccess::class),
            ))->get();
            $this->fail('A revoked product grant must deny the workspace.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_core_billing_manager_can_open_monitor_billing_without_a_product_grant_or_local_membership(): void
    {
        Artisan::call('platform:migrate', ['module' => 'core']);
        config(['platform.products.monitor.auth_authority' => 'core']);

        $billingManager = PlatformUser::query()->forceCreate([
            'id' => (string) Str::ulid(),
            'name' => 'Billing manager',
            'email' => 'monitor-billing-manager@example.test',
            'email_normalized' => 'monitor-billing-manager@example.test',
            'password' => 'hashed-password',
            'status' => 'active',
        ]);
        $owner = PlatformUser::query()->forceCreate([
            'id' => (string) Str::ulid(),
            'name' => 'Workspace owner',
            'email' => 'monitor-workspace-owner@example.test',
            'email_normalized' => 'monitor-workspace-owner@example.test',
            'password' => 'hashed-password',
            'status' => 'active',
        ]);
        $workspaceId = (string) Str::ulid();
        $membershipId = (string) Str::ulid();
        DB::connection('core')->table('workspaces')->insert([
            'id' => $workspaceId,
            'owner_user_id' => $owner->getKey(),
            'name' => 'Billing workspace',
            'slug' => 'monitor-billing-'.Str::lower(Str::random(5)),
            'status' => 'active',
            'settings' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('workspace_memberships')->insert([
            'id' => $membershipId,
            'workspace_id' => $workspaceId,
            'user_id' => $billingManager->getKey(),
            'role' => 'billing',
            'status' => 'active',
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productUserId = DB::connection('monitor')->table('users')->insertGetId([
            'name' => 'Local Monitor principal',
            'email' => 'local-monitor@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sourceWorkspaceId = DB::connection('monitor')->table('workspaces')->insertGetId([
            'owner_id' => $productUserId,
            'name' => 'Billing workspace',
            'slug' => 'monitor-billing-workspace',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('legacy_identity_maps')->insert([
            'id' => (string) Str::ulid(),
            'source_product' => 'monitor',
            'source_entity' => 'workspace',
            'source_id' => (string) $sourceWorkspaceId,
            'canonical_entity' => 'workspace',
            'canonical_id' => $workspaceId,
            'status' => 'reconciled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $billingRoute = new RoutingRoute(['GET', 'HEAD'], 'settings/billing', ['as' => 'monitor.settings.billing']);
        $request = Request::create('/settings/billing?workspace_id='.$sourceWorkspaceId);
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(static fn (): User => User::query()->findOrFail($productUserId));
        $request->attributes->set('platform_user', $billingManager);
        $request->setRouteResolver(static fn () => $billingRoute);

        $currentWorkspace = new CurrentWorkspace(
            $request,
            app(ProductAuthentication::class),
            app(ProductWorkspaceAccess::class),
        );
        $workspace = $currentWorkspace->get();
        $currentWorkspace->authorizeBilling($workspace);

        $this->assertSame($sourceWorkspaceId, $workspace->getKey());
        $this->assertFalse($workspace->members()->whereKey($productUserId)->exists());
        $this->assertSame($sourceWorkspaceId, $request->session()->get('monitor_billing_workspace_id'));

        DB::connection('core')->table('workspace_memberships')
            ->where('id', $membershipId)
            ->update(['role' => 'member']);

        try {
            (new CurrentWorkspace(
                $request,
                app(ProductAuthentication::class),
                app(ProductWorkspaceAccess::class),
            ))->get();
            $this->fail('A regular Core workspace member cannot use Monitor billing without an app grant.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    private function requestFor(User $user, int $workspaceId): Request
    {
        $request = Request::create('/incidents/4?workspace_id='.$workspaceId);
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(static fn (): User => $user);

        return $request;
    }
}
