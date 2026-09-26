<?php

namespace Tests\Feature\Core;

use App\Core\Auth\PlatformUserProvider;
use App\Core\Models\PlatformUser;
use App\Core\Services\Auth\ConnectPlatformSocialIdentity;
use App\Core\Services\Auth\PlatformTwoFactorSettings;
use App\Core\Services\Deletion\DeletionPlanner;
use App\Core\Services\Identity\CoordinateIdentityProjection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class IdentityProjectionLifecycleTest extends TestCase
{
    private PlatformUser $actor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.core.database' => ':memory:']);
        DB::purge('core');
        Artisan::call('platform:migrate', ['module' => 'core']);
        $this->actor = PlatformUser::query()->create(['name' => 'Owner', 'email' => 'owner@example.test', 'status' => 'active']);
    }

    public function test_an_in_flight_projection_blocks_deletion_and_cannot_be_stolen_by_another_visit(): void
    {
        $coordinator = app(CoordinateIdentityProjection::class);
        $coordinator->run('analytics', $this->actor, function () use ($coordinator): void {
            $this->assertContains('identity_projection_in_progress', app(DeletionPlanner::class)->plan($this->actor)['blockers']);
            try {
                $coordinator->run('analytics', $this->actor, fn () => $this->fail('A second source writer must not start.'));
                $this->fail('The existing projection must stay fenced.');
            } catch (HttpException $exception) {
                $this->assertSame(409, $exception->getStatusCode());
            }
            $this->assertSame(1, DB::connection('core')->table('identity_projection_operations')->count());
        });

        $this->assertSame(0, DB::connection('core')->table('identity_projection_operations')->count());
        $this->assertNotContains('identity_projection_in_progress', app(DeletionPlanner::class)->plan($this->actor)['blockers']);
    }

    public function test_a_caught_source_failure_keeps_deletion_blocked_until_successful_projection_retry(): void
    {
        $coordinator = app(CoordinateIdentityProjection::class);
        try {
            $coordinator->run('analytics', $this->actor, fn () => throw new RuntimeException('Source outcome needs reconciliation.'));
            $this->fail('The original projection error must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Source outcome needs reconciliation.', $exception->getMessage());
        }
        $this->assertContains('identity_projection_in_progress', app(DeletionPlanner::class)->plan($this->actor)['blockers']);
        $this->assertSame('failed', DB::connection('core')->table('identity_projection_operations')->value('status'));

        $this->assertSame('mapped', $coordinator->run('analytics', $this->actor, fn () => 'mapped'));
        $this->assertSame(0, DB::connection('core')->table('identity_projection_operations')->count());
    }

    public function test_a_stale_user_instance_cannot_recreate_native_projection_after_deletion_started(): void
    {
        PlatformUser::query()->whereKey($this->actor->getKey())->update(['status' => 'deleting']);
        $this->expectException(HttpException::class);
        app(CoordinateIdentityProjection::class)->run('analytics', $this->actor, fn () => $this->fail('No source provisioning is authorized.'));
    }

    public function test_security_changes_cannot_repopulate_an_account_tombstone_using_a_stale_user(): void
    {
        PlatformUser::query()->whereKey($this->actor->getKey())->update(['status' => 'deleted', 'email' => null]);
        foreach ([
            fn () => app(PlatformTwoFactorSettings::class)->begin($this->actor),
            fn () => app(ConnectPlatformSocialIdentity::class)->handle($this->actor, 'github', 'provider-user', 'owner@example.test'),
        ] as $operation) {
            try {
                $operation();
                $this->fail('A stale authenticated request cannot add credentials after deletion.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        $this->assertNull($this->actor->fresh()->two_factor_secret);
        $this->assertSame(0, $this->actor->identities()->count());
    }

    public function test_logout_cannot_write_a_new_remember_token_to_a_deleted_account(): void
    {
        $this->actor->forceFill(['remember_token' => 'old-remember-token'])->save();
        PlatformUser::query()->whereKey($this->actor->getKey())->update(['status' => 'deleted', 'remember_token' => null]);
        $provider = new PlatformUserProvider(app('hash'), PlatformUser::class);
        $provider->updateRememberToken($this->actor, 'rotated-after-deletion');

        $this->assertNull($this->actor->fresh()->remember_token);
        $this->assertNull($this->actor->getRememberToken());
    }
}
