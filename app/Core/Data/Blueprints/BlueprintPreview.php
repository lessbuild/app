<?php

namespace App\Core\Data\Blueprints;

final readonly class BlueprintPreview
{
    /** @param array<string, BlueprintProductPreview> $products */
    public function __construct(public BlueprintTarget $target, public array $products, public string $fingerprint, public array $coreBindings = []) {}

    public function ready(): bool
    {
        foreach ($this->products as $preview) {
            if (! $preview->ready()) {
                return false;
            }
        }

        return $this->products !== [];
    }
}
