<?php

namespace App\Modules\Monitor\Http\Resources;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeploymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'deployment_id' => $this->deployment_key,
            'environment_id' => $this->environment_id, 'release_id' => $this->release_id,
            'version' => $this->release->version, 'service' => $this->release->service,
            'service_namespace' => $this->release->service_namespace,
            'commit_sha' => $this->commit_sha, 'deployed_at' => $this->deployed_at->toISOString(),
            'replayed' => ! $this->resource->wasRecentlyCreated,
        ];
    }

    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->setStatusCode($this->resource->wasRecentlyCreated ? 201 : 200);
        $response->headers->set('Cache-Control', 'private, no-store');
    }
}
