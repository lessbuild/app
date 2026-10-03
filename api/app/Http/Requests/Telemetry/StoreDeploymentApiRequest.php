<?php

declare(strict_types=1);

namespace App\Http\Requests\Telemetry;

final class StoreDeploymentApiRequest extends StoreDeploymentRequest
{
    /**
     * Get the JSON body to validate, which the middleware decoded.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->json()->all();
    }
}
