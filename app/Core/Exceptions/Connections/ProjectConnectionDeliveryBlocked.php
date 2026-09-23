<?php

namespace App\Core\Exceptions\Connections;

use RuntimeException;

final class ProjectConnectionDeliveryBlocked extends RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct($reasonCode);
    }
}
