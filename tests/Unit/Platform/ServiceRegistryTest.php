<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use App\Domain\Billing\Catalog\ServiceBilling;
use App\Domain\Billing\Catalog\Tier;
use App\Platform\ServiceRegistry;
use App\Platform\Services\PlaceholderService;
use InvalidArgumentException;
use Tests\TestCase;

final class ServiceRegistryTest extends TestCase
{
    public function test_the_platform_registers_the_four_services_in_order(): void
    {
        $this->assertSame(['deploy', 'infrastructure', 'monitoring', 'analytics'], app(ServiceRegistry::class)->keys());
    }

    public function test_keys_are_unique(): void
    {
        $registry = new ServiceRegistry;
        $registry->register(new PlaceholderService('x', 'X', 'x', 'cog', [], new ServiceBilling([new Tier('free', 'Free', 0, 'x')])));

        $this->expectException(InvalidArgumentException::class);
        $registry->register(new PlaceholderService('x', 'Another X', 'x', 'cog', [], new ServiceBilling([new Tier('free', 'Free', 0, 'x')])));
    }
}
