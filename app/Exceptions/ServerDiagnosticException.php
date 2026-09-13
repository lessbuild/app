<?php

namespace App\Exceptions;

use App\Enums\ServerDiagnosticFailureStage;
use RuntimeException;

final class ServerDiagnosticException extends RuntimeException
{
    public function __construct(
        public readonly ServerDiagnosticFailureStage $stage,
        string $message,
    ) {
        parent::__construct($message);
    }
}
