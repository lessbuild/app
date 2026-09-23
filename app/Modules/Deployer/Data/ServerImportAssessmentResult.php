<?php

namespace App\Modules\Deployer\Data;

use App\Modules\Deployer\Models\ServerImportAssessment;

class ServerImportAssessmentResult
{
    public function __construct(
        public readonly ServerImportAssessment $assessment,
        public readonly string $token,
    ) {}
}
