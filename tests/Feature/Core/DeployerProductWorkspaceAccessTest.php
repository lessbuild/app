<?php

namespace Tests\Feature\Core;

use App\Core\Models\PlatformUser;
use App\Core\Services\Auth\ProductAuthentication;
use App\Core\Services\Identity\ProductWorkspaceAccess;
use App\Modules\Deployer\Http\Middleware\EnsureCoreProductWorkspaceAccess;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\User as DeployerUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class DeployerProductWorkspaceAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('platform:migrate', ['module' => 'core']);
        Schema::connection('deployer')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->unsignedBigInteger('current_organization_id')->nullable();
            $table->timestamps();
        });
        Schema::connection('deployer')->create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('owner_id');
            $table->string('name');
            $table->string('slug');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::connection('deployer')->dropIfExists('organizations');
        Schema::connection('deployer')->dropIfExists('users');

        parent::tearDown();
    }

    public function test_deployer_route_access_follows_the_current_organizations_core_product_grant(): void
    {
        config(['platform.products.deployer.auth_authority' => 'core']);

        $platformUser = PlatformUser::query()->forceCreate([
            'id' => (string) Str::ulid(),
            'name' => 'Core Deployer member',
            'email' => 'core-deployer@example.test',
            'email_normalized' => 'core-deployer@example.test',
            'password' => 'hashed-password',
            'status' => 'active',
        ]);
        $canonicalWorkspaceId = (string) Str::ulid();
        $membershipId = (string) Str::ulid();
        DB::connection('core')->table('workspaces')->insert([
            'id' => $canonicalWorkspaceId,
            'owner_user_id' => $platformUser->getKey(),
            'name' => 'Core Deployer workspace',
            'slug' => 'core-deployer-workspace',
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
            'product' => 'deployer',
            'role' => 'owner',
            'status' => 'active',
            'granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $deployerUserId = DB::connection('deployer')->table('users')->insertGetId([
            'name' => 'Deployer member',
            'email' => 'deployer-member@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $organizationId = DB::connection('deployer')->table('organizations')->insertGetId([
            'owner_id' => $deployerUserId,
            'name' => 'Core Deployer workspace',
            'slug' => 'core-deployer-workspace',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('deployer')->table('users')->where('id', $deployerUserId)->update([
            'current_organization_id' => $organizationId,
        ]);
        DB::connection('core')->table('legacy_identity_maps')->insert([
            'id' => (string) Str::ulid(),
            'source_product' => 'deployer',
            'source_entity' => 'organization',
            'source_id' => (string) $organizationId,
            'canonical_entity' => 'workspace',
            'canonical_id' => $canonicalWorkspaceId,
            'status' => 'reconciled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = DeployerUser::query()->findOrFail($deployerUserId);
        $user->setRelation('currentOrganization', Organization::query()->findOrFail($organizationId));
        $request = Request::create('/projects');
        $request->setUserResolver(static fn (): DeployerUser => $user);
        $request->attributes->set('platform_user', $platformUser);
        $middleware = new EnsureCoreProductWorkspaceAccess(
            app(ProductAuthentication::class),
            app(ProductWorkspaceAccess::class),
        );

        $this->assertSame('granted', $middleware->handle($request, static fn (): Response => new Response('granted'))->getContent());

        DB::connection('core')->table('workspace_product_access')->where('id', $grantId)->update([
            'status' => 'revoked',
            'revoked_at' => now(),
        ]);

        try {
            $middleware->handle($request, static fn (): Response => new Response('unexpected'));
            $this->fail('A revoked Deployer grant must deny the current organization.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }
}
