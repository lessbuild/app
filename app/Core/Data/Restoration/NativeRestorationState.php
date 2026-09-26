<?php

namespace App\Core\Data\Restoration;

final readonly class NativeRestorationState
{
    public function __construct(public string $resourceType, public string $resourceId, public string $status) {}

    /** @return array{resourceType:string,resourceId:string,status:string} */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
