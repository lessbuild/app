<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Http\Requests\StoreDeploymentApiRequest;
use App\Modules\Monitor\Http\Resources\DeploymentResource;
use App\Modules\Monitor\Services\RecordDeployment;

class DeploymentIngestController extends Controller
{
    public function store(StoreDeploymentApiRequest $request, RecordDeployment $recorder): DeploymentResource
    {
        return new DeploymentResource($recorder->record(
            $request->attributes->get('ingest_environment'),
            $request->validated(),
            token: $request->attributes->get('ingest_token'),
        ));
    }
}
