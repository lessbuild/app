<?php

namespace Tests\Unit\Core;

use App\Core\Contracts\ProjectConnectionOutboxDispatcher;
use App\Core\Data\Connections\ProjectConnectionOutboxEvent;
use App\Core\Services\Connections\ProjectConnectionOutboxDispatcherRegistry;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ProjectConnectionOutboxDispatcherRegistryTest extends TestCase
{
    public function test_it_resolves_a_dispatcher_by_its_declared_product(): void
    {
        $registry = new ProjectConnectionOutboxDispatcherRegistry;
        $dispatcher = $this->dispatcher('deployer');
        $registry->register('deployer', $dispatcher);

        $this->assertSame($dispatcher, $registry->get('deployer'));
        $this->assertNull($registry->get('monitor'));
    }

    public function test_it_rejects_a_dispatcher_registered_for_another_product(): void
    {
        $registry = new ProjectConnectionOutboxDispatcherRegistry;

        $this->expectException(LogicException::class);

        $registry->register('deployer', $this->dispatcher('monitor'));
    }

    public function test_it_rejects_conflicting_dispatchers_for_one_product(): void
    {
        $registry = new ProjectConnectionOutboxDispatcherRegistry;
        $registry->register('deployer', $this->dispatcher('deployer'));

        $this->expectException(LogicException::class);

        $registry->register('deployer', $this->dispatcher('deployer'));
    }

    private function dispatcher(string $product): ProjectConnectionOutboxDispatcher
    {
        return new class($product) implements ProjectConnectionOutboxDispatcher
        {
            public function __construct(private string $product) {}

            public function product(): string
            {
                return $this->product;
            }

            public function dispatch(ProjectConnectionOutboxEvent $event): int
            {
                return 0;
            }

            public function missingDeliveryCount(ProjectConnectionOutboxEvent $event): int
            {
                return 0;
            }
        };
    }
}
