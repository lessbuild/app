<?php

namespace Tests\Unit\Core;

use App\Core\Contracts\ProjectConnectionDeliveryConsumer;
use App\Core\Enums\ProjectConnectionCapability;
use App\Core\Services\Connections\ProjectConnectionDeliveryConsumerRegistry;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ProjectConnectionDeliveryConsumerRegistryTest extends TestCase
{
    public function test_it_resolves_only_the_registered_event_capability_and_target_product(): void
    {
        $registry = new ProjectConnectionDeliveryConsumerRegistry;
        $consumer = $this->consumer(
            ['deployer.deployment_succeeded'],
            ProjectConnectionCapability::DeploymentContext,
            'monitor',
        );
        $registry->register($consumer);

        $this->assertSame(
            $consumer,
            $registry->resolve(
                'deployer.deployment_succeeded',
                [ProjectConnectionCapability::DeploymentContext->value],
                'monitor',
            ),
        );
        $this->assertNull($registry->resolve(
            'monitor.incident_opened',
            [ProjectConnectionCapability::DeploymentContext->value],
            'monitor',
        ));
        $this->assertNull($registry->resolve(
            'deployer.deployment_succeeded',
            [ProjectConnectionCapability::ReleaseAnnotations->value],
            'analytics',
        ));
        $this->assertNull($registry->resolve(
            'deployer.deployment_succeeded',
            [ProjectConnectionCapability::DeploymentContext->value],
            'analytics',
        ));
    }

    public function test_it_registers_one_consumer_for_multiple_explicit_event_types(): void
    {
        $registry = new ProjectConnectionDeliveryConsumerRegistry;
        $consumer = $this->consumer(
            ['monitor.incident_opened', 'monitor.incident_resolved'],
            ProjectConnectionCapability::IncidentAnnotations,
            'analytics',
        );
        $registry->register($consumer);

        $this->assertSame($consumer, $registry->resolve(
            'monitor.incident_opened',
            [ProjectConnectionCapability::IncidentAnnotations->value],
            'analytics',
        ));
        $this->assertSame($consumer, $registry->resolve(
            'monitor.incident_resolved',
            [ProjectConnectionCapability::IncidentAnnotations->value],
            'analytics',
        ));
    }

    public function test_it_rejects_conflicting_consumers_for_the_same_route(): void
    {
        $registry = new ProjectConnectionDeliveryConsumerRegistry;
        $registry->register($this->consumer(
            ['deployer.deployment_succeeded'],
            ProjectConnectionCapability::DeploymentContext,
            'monitor',
        ));

        $this->expectException(LogicException::class);

        $registry->register($this->consumer(
            ['deployer.deployment_succeeded'],
            ProjectConnectionCapability::DeploymentContext,
            'monitor',
        ));
    }

    /**
     * @param  list<string>  $eventTypes
     */
    private function consumer(
        array $eventTypes,
        ProjectConnectionCapability $capability,
        string $targetProduct,
    ): ProjectConnectionDeliveryConsumer {
        return new class($eventTypes, $capability, $targetProduct) implements ProjectConnectionDeliveryConsumer
        {
            /** @param list<string> $eventTypes */
            public function __construct(
                private array $eventTypes,
                private ProjectConnectionCapability $capability,
                private string $targetProduct,
            ) {}

            public function eventTypes(): array
            {
                return $this->eventTypes;
            }

            public function capability(): ProjectConnectionCapability
            {
                return $this->capability;
            }

            public function targetProduct(): string
            {
                return $this->targetProduct;
            }

            public function consume(string $deliveryId, string $connectionId, array $payload): void {}
        };
    }
}
