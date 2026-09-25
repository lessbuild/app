<?php

namespace App\Core\Exceptions\Deletion;

use RuntimeException;

final class DeletionBlocked extends RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct($reasonCode);
    }
}
