<?php

namespace App\Core\Exceptions\Restoration;

use RuntimeException;

class ResourceRestorationBlocked extends RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct($reasonCode);
    }
}
