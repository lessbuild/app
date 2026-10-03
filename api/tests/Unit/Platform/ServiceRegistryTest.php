<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use App\Platform\ServiceRegistry;
use App\Platform\Services\DeployService;
use InvalidArgumentException;
use Tests\TestCase;

final class ServiceRegistryTest extends TestCase
{
    public function test_the_platform_registers_the_five_services_in_order(): void
    {
        $this->assertSame(['deploy', 'infrastructure', 'monitoring', 'analytics', 'security', 'audit'], app(ServiceRegistry::class)->keys());
    }

    public function test_keys_are_unique(): void
    {
        $registry = new ServiceRegistry;
        $registry->register(new DeployService);

        $this->expectException(InvalidArgumentException::class);
        $registry->register(new DeployService);
    }
}
