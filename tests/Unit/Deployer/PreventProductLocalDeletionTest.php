<?php

namespace Tests\Unit\Deployer;

use App\Modules\Deployer\Http\Middleware\PreventProductLocalDeletion;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class PreventProductLocalDeletionTest extends TestCase
{
    public function test_core_authority_blocks_product_local_account_and_workspace_deletion(): void
    {
        config(['platform.products.deployer.auth_authority' => 'core']);
        $middleware = app(PreventProductLocalDeletion::class);

        foreach (['account', 'workspace'] as $resource) {
            try {
                $middleware->handle(Request::create('/'), static fn () => response('continued'), $resource);
                $this->fail("Core authority should block local {$resource} deletion.");
            } catch (HttpException $exception) {
                $this->assertSame(409, $exception->getStatusCode());
                $this->assertStringContainsString('No data was changed.', $exception->getMessage());
            }
        }
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
