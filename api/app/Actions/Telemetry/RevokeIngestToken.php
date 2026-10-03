<?php

declare(strict_types=1);

namespace App\Actions\Telemetry;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Models\Environment;
use App\Models\IngestToken;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class RevokeIngestToken
{
    /**
     * Create a new RevokeIngestToken instance.
     *
     * Revokes an ingest token.
     *
     * @param  RecordAuditEntry  $audit  Records the revocation.
     */
    public function __construct(private readonly RecordAuditEntry $audit) {}

    /**
     * Stop accepting a key. Requests that use it are refused from now on.
     *
     * @param  User  $actor
     * @param  IngestToken  $token
     * @return void
     */
    public function handle(User $actor, IngestToken $token): void
    {
        DB::transaction(function () use ($actor, $token): void {
            $environment = Environment::query()->findOrFail($token->environment_id);
            $project = Project::query()->lockForUpdate()->findOrFail($environment->project_id);
            Gate::forUser($actor)->authorize('delete', $token);
            $token = $environment->ingestTokens()->lockForUpdate()->findOrFail($token->id);
            if ($token->revoked_at !== null) {
                return;
            }
            $token->forceFill(['revoked_at' => now()])->save();
            $this->audit->handle(AuditAction::IngestTokenRevoked, $actor, $project->account_id, [
                'name' => $token->name, 'environment' => $environment->name, 'project' => $project->name,
            ], $project->id);
        });
    }
}
