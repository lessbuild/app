<?php

declare(strict_types=1);

namespace App\Http\Requests\Telemetry;

final class StoreDeploymentApiRequest extends StoreDeploymentRequest
{
    /**
     * The JSON body, which the middleware decoded.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->json()->all();
    }
}
