<?php

namespace App\Core\Exceptions\Blueprints;

use RuntimeException;

final class BlueprintBlocked extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
