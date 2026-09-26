<?php

namespace App\Core\Services\Connections;

use App\Core\Contracts\ProjectConnectionDeliveryConsumer;
use App\Core\Enums\ProjectConnectionCapability;
use LogicException;

final class ProjectConnectionDeliveryConsumerRegistry
{
    /** @var array<string, ProjectConnectionDeliveryConsumer> */
    private array $consumers = [];

    public function register(ProjectConnectionDeliveryConsumer $consumer): void
    {
        $eventTypes = $consumer->eventTypes();

        if ($eventTypes === []) {
            throw new LogicException('A project-connection delivery consumer must declare event types.');
        }

        foreach ($eventTypes as $eventType) {
            if ($eventType === '') {
                throw new LogicException('A project-connection delivery consumer must declare event types.');
            }

            $key = $this->key($eventType, $consumer->capability(), $consumer->targetProduct());

            if (isset($this->consumers[$key]) && get_class($this->consumers[$key]) !== get_class($consumer)) {
                throw new LogicException("A project-connection delivery consumer is already registered for {$key}.");
            }

            $this->consumers[$key] = $consumer;
        }
    }

    /**
     * @param  list<string>  $capabilities
     */
    public function resolve(string $eventType, array $capabilities, string $targetProduct): ?ProjectConnectionDeliveryConsumer
    {
        foreach ($capabilities as $capability) {
            $value = ProjectConnectionCapability::tryFrom($capability);

            if ($value === null) {
                continue;
            }

            $consumer = $this->consumers[$this->key($eventType, $value, $targetProduct)] ?? null;

            if ($consumer !== null) {
                return $consumer;
            }
        }

        return null;
    }

    private function key(string $eventType, ProjectConnectionCapability $capability, string $targetProduct): string
    {
        return implode(':', [$eventType, $capability->value, $targetProduct]);
    }
}
