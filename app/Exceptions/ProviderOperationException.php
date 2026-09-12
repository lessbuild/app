<?php

namespace App\Exceptions;

use RuntimeException;

class ProviderOperationException extends RuntimeException
{
    public function __construct(
        public readonly string $field,
        string $message,
        public readonly bool $withInput = false,
    ) {
        parent::__construct($message);
    }
}
