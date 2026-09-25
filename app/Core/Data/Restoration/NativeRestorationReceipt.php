<?php

namespace App\Core\Data\Restoration;

final readonly class NativeRestorationReceipt
{
    /** @param list<NativeRestorationState> $states */
    public function __construct(public string $requestId, public int $revision, public array $states) {}

    public function toArray(): array
    {
        $states = array_map(fn (NativeRestorationState $state): array => $state->toArray(), $this->states);
        usort($states, fn (array $left, array $right): int => [$left['resourceType'], $left['resourceId']] <=> [$right['resourceType'], $right['resourceId']]);

        return ['requestId' => $this->requestId, 'revision' => $this->revision, 'states' => $states];
    }

    public function hash(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_THROW_ON_ERROR));
    }
}
