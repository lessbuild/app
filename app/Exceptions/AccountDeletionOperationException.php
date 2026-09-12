<?php

namespace App\Exceptions;

use RuntimeException;

class AccountDeletionOperationException extends RuntimeException
{
    public function __construct(string $message, public readonly int $statusCode)
    {
        parent::__construct($message);
    }
}
