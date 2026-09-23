<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Data\Telemetry\IssuedIngestToken;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateIngestToken
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    public function create(Environment $environment, User $creator, string $name, ?CarbonInterface $expiresAt = null, ?string $auditAction = 'ingest_token.created'): IssuedIngestToken
    {
        return DB::connection('monitor')->transaction(function () use ($environment, $creator, $name, $expiresAt, $auditAction): IssuedIngestToken {
            $application = Application::query()->lockForUpdate()->findOrFail($environment->application_id);
            $workspace = Workspace::query()->lockForUpdate()->findOrFail($application->workspace_id);
            $environment = Environment::query()->lockForUpdate()->findOrFail($environment->id);
            $secret = 'bcn_'.Str::random(64);
            $token = $environment->ingestTokens()->make([
                'name' => $name,
                'token_hash' => hash('sha256', $secret),
                'prefix' => substr($secret, 0, 12),
                'expires_at' => $expiresAt,
            ]);
            $token->creator()->associate($creator);
            $token->save();
            $token->setRelation('environment', $environment);

            if ($auditAction !== null) {
                $this->audit->record($workspace, $creator, $auditAction, $token, ['label' => $token->name, 'environment' => $environment->name, 'application' => $application->name]);
            }

            return new IssuedIngestToken($token, $secret);
        });
    }
}
