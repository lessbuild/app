<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\IngestToken;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class RevokeIngestToken
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    public function revoke(IngestToken $token, User $actor, ?Environment $expectedEnvironment = null, ?Application $expectedApplication = null): void
    {
        DB::connection('monitor')->transaction(function () use ($token, $actor, $expectedEnvironment, $expectedApplication): void {
            $environmentRef = Environment::query()->findOrFail($token->environment_id);
            abort_unless($expectedEnvironment === null || (string) $expectedEnvironment->getKey() === (string) $environmentRef->getKey(), 404);
            $application = Application::query()->lockForUpdate()->findOrFail($environmentRef->application_id);
            abort_unless($expectedApplication === null || (string) $expectedApplication->getKey() === (string) $application->getKey(), 404);
            $workspace = Workspace::query()->lockForUpdate()->findOrFail($application->workspace_id);
            $environment = Environment::query()->lockForUpdate()->findOrFail($environmentRef->getKey());
            $token = $environment->ingestTokens()->lockForUpdate()->findOrFail($token->getKey());
            Gate::forUser($actor)->authorize('update', $environment);
            if ($token->revoked_at === null) {
                $token->forceFill(['revoked_at' => now()])->save();
                $this->audit->record($workspace, $actor, 'ingest_token.revoked', $token, [
                    'label' => $token->name,
                    'environment' => $environment->name,
                    'application' => $application->name,
                ]);
            }
        });
    }
}
