<?php

namespace Tests\Unit;

use App\Core\Models\PlatformUser;
use App\Modules\Analytics\Models\User as AnalyticsUser;
use App\Modules\Analytics\Services\AnalyticsHorizonAccess;
use Tests\TestCase;

final class AnalyticsHorizonAccessTest extends TestCase
{
    public function test_only_an_active_core_platform_admin_can_view_analytics_horizon(): void
    {
        config(['lessbuild.platform_admin_emails' => ['ops@example.com']]);
        $access = app(AnalyticsHorizonAccess::class);

        $admin = new PlatformUser(['email' => ' OPS@example.com ', 'status' => 'active']);
        $disabledAdmin = new PlatformUser(['email' => 'ops@example.com', 'status' => 'suspended']);
        $legacyUser = new AnalyticsUser(['email' => 'ops@example.com']);

        $this->assertTrue($access->allows($admin));
        $this->assertFalse($access->allows($disabledAdmin));
        $this->assertFalse($access->allows($legacyUser));
        $this->assertFalse($access->allows(null));
    }
}
