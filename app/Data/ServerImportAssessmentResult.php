<?php

namespace App\Data;

use App\Models\ServerImportAssessment;

class ServerImportAssessmentResult
{
    public function __construct(
        public readonly ServerImportAssessment $assessment,
        public readonly string $token,
    ) {}
}
