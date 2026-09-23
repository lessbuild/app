<?php

namespace App\Modules\Deployer\Exceptions;

use App\Modules\Deployer\Enums\ServerDiagnosticFailureStage;
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
