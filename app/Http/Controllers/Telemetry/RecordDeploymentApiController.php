<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Actions\Telemetry\RecordDeployment;
use App\Http\Requests\Telemetry\StoreDeploymentApiRequest;
use App\Models\Environment;
use App\Models\IngestToken;
use Illuminate\Http\JsonResponse;

/** POST /api/v1/deployments: public contract from the old Monitor app. 201 when new, 200 when replayed. */
final class RecordDeploymentApiController
{
    public function __invoke(StoreDeploymentApiRequest $request, RecordDeployment $record): JsonResponse
    {
        $environment = $request->attributes->get('ingest_environment');
        $token = $request->attributes->get('ingest_token');
        abort_unless($environment instanceof Environment && $token instanceof IngestToken, 401);
        $deployment = $record->handle($environment, $request->deployment(), token: $token);

        return response()->json(['data' => [
            'id' => $deployment->id, 'deployment_id' => $deployment->deployment_key,
            'environment_id' => $deployment->environment_id, 'release_id' => $deployment->release_id,
            'version' => $deployment->release->version, 'service' => $deployment->release->service,
            'service_namespace' => $deployment->release->service_namespace,
            'commit_sha' => $deployment->commit_sha, 'deployed_at' => $deployment->deployed_at->toISOString(),
            'replayed' => ! $deployment->wasRecentlyCreated,
        ]], $deployment->wasRecentlyCreated ? 201 : 200)->header('Cache-Control', 'private, no-store');
    }
}
