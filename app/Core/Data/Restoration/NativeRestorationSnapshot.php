<?php

namespace App\Core\Data\Restoration;

final readonly class NativeRestorationSnapshot
{
    /** @param list<NativeRestorationState> $states */
    public function __construct(public int $revision, public array $states, public ?NativeRestorationReceipt $currentReceipt = null, public ?string $parentApplicationId = null) {}
}
