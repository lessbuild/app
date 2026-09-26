<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Data\Telemetry\IssuedIngestToken;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\IngestToken;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use Illuminate\Support\Facades\DB;

final class RotateIngestToken
{
    public function __construct(private readonly CreateIngestToken $createToken, private readonly RecordAuditLog $audit) {}

    public function rotate(IngestToken $token, User $creator): IssuedIngestToken
    {
        return DB::connection('monitor')->transaction(function () use ($token, $creator): IssuedIngestToken {
            $environment = $token->environment;
            abort_unless($environment, 404);
            $application = Application::query()->lockForUpdate()->findOrFail($environment->application_id);
            $workspace = Workspace::query()->lockForUpdate()->findOrFail($application->workspace_id);
            $environment = Environment::query()->lockForUpdate()->findOrFail($environment->id);
            $token = $environment->ingestTokens()->lockForUpdate()->findOrFail($token->id);
            abort_unless($token->status() === 'active', 409, 'Only an active token can be rotated.');

            $issued = $this->createToken->create($environment, $creator, $token->name, $token->expires_at, null);
            $token->forceFill(['revoked_at' => now()])->save();
            $this->audit->record($workspace, $creator, 'ingest_token.rotated', $token, ['label' => $token->name, 'environment' => $environment->name, 'application' => $application->name, 'replacement_prefix' => $issued->token->prefix]);

            return $issued;
        });
    }
}
