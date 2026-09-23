<?php

namespace App\Modules\Monitor\Http\Requests;

use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\IngestToken;
use App\Modules\Monitor\Services\CurrentWorkspace;

class StoreDeploymentApiRequest extends StoreDeploymentRequest
{
    public function authorize(CurrentWorkspace $workspace): bool
    {
        return $this->attributes->get('ingest_environment') instanceof Environment
            && $this->attributes->get('ingest_token') instanceof IngestToken;
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->json()->all();
    }
}
