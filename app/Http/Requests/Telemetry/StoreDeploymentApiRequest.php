<?php

declare(strict_types=1);

namespace App\Http\Requests\Telemetry;

final class StoreDeploymentApiRequest extends StoreDeploymentRequest
{
    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->json()->all();
    }
}
