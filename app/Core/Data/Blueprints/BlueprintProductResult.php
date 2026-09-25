<?php

namespace App\Core\Data\Blueprints;

final readonly class BlueprintProductResult
{
    /** @param list<BlueprintResource> $resources
     * @param  list<string>  $requirements
     */
    public function __construct(public array $resources, public array $requirements = []) {}

    public function toArray(): array
    {
        return [
            'resources' => array_map(fn (BlueprintResource $resource): array => $resource->toArray(), $this->resources),
            'requirements' => $this->requirements,
        ];
    }

    public static function fromArray(array $receipt): self
    {
        return new self(array_map(fn (array $resource): BlueprintResource => new BlueprintResource(...$resource), $receipt['resources']), $receipt['requirements'] ?? []);
    }
}
