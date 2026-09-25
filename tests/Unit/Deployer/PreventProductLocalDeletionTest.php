<?php

namespace Tests\Unit\Deployer;

use App\Core\Models\LegacyIdentityMap;
use App\Modules\Deployer\Http\Middleware\PreventProductLocalDeletion;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class PreventProductLocalDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_authority_requires_reconciled_native_account_mapping_for_handoff(): void
    {
        config(['platform.products.deployer.auth_authority' => 'core']);
        $nativeUser = User::factory()->create();
        $request = Request::create('/account/delete', 'DELETE');
        $request->setUserResolver(static fn () => $nativeUser);
        $localDeleteCalled = false;

        try {
            app(PreventProductLocalDeletion::class)->handle($request, static function () use (&$localDeleteCalled) {
                $localDeleteCalled = true;

                return response('local delete ran');
            }, 'account');
            $this->fail('A missing canonical account mapping must stop deletion handoff.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        LegacyIdentityMap::query()->create([
            'source_product' => 'deployer', 'source_entity' => 'user', 'source_id' => (string) $nativeUser->getKey(),
            'canonical_entity' => 'user', 'canonical_id' => '01JABCDEF0123456789ABCDEFGH',
            'status' => 'reconciled', 'batch_key' => 'deletion-handoff-test',
        ]);
        $response = app(PreventProductLocalDeletion::class)->handle($request, static function () use (&$localDeleteCalled) {
            $localDeleteCalled = true;

            return response('local delete ran');
        }, 'account');

        $this->assertSame(route('platform.deletions.account.create'), $response->getTargetUrl());
        $this->assertFalse($localDeleteCalled);
    }

    public function test_core_authority_requires_reconciled_workspace_mapping_and_uses_workspace_entity(): void
    {
        config(['platform.products.deployer.auth_authority' => 'core']);
        $workspace = new Organization(['id' => 731]);
        $request = Request::create('/organizations/731/delete', 'DELETE');
        $route = new Route('DELETE', '/organizations/{organization}/delete', []);
        $route->setParameter('organization', $workspace);
        $request->setRouteResolver(static fn () => $route);
        $localDeleteCalled = false;

        try {
            app(PreventProductLocalDeletion::class)->handle($request, static function () use (&$localDeleteCalled) {
                $localDeleteCalled = true;

                return response('local delete ran');
            }, 'workspace');
            $this->fail('A missing canonical workspace mapping must stop deletion handoff.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
        $this->assertFalse($localDeleteCalled);

        LegacyIdentityMap::query()->create([
            'source_product' => 'deployer', 'source_entity' => 'organization', 'source_id' => '731',
            'canonical_entity' => 'workspace', 'canonical_id' => '01JABCDEF0123456789ABCDEFGH',
            'status' => 'reconciled', 'batch_key' => 'deletion-handoff-test',
        ]);
        $response = app(PreventProductLocalDeletion::class)->handle($request, static function () use (&$localDeleteCalled) {
            $localDeleteCalled = true;

            return response('local delete ran');
        }, 'workspace');

        $this->assertSame(route('platform.deletions.workspace.create', ['workspace' => '01JABCDEF0123456789ABCDEFGH']), $response->getTargetUrl());
        $this->assertFalse($localDeleteCalled);
    }

    public function test_core_authority_without_platform_actor_is_not_a_native_delete(): void
    {
        config(['platform.products.deployer.auth_authority' => 'core']);
        $called = false;

        try {
            app(PreventProductLocalDeletion::class)->handle(Request::create('/account/delete', 'DELETE'), static function () use (&$called) {
                $called = true;

                return response('local delete ran');
            }, 'account');
            $this->fail('A request without a canonical account must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertFalse($called);
    }

    public function test_legacy_authority_keeps_existing_local_deletion_behavior(): void
    {
        config(['platform.products.deployer.auth_authority' => 'legacy']);

        $response = app(PreventProductLocalDeletion::class)->handle(
            Request::create('/'),
            static fn () => response('continued'),
            'account',
        );

        $this->assertSame('continued', $response->getContent());
    }
}
