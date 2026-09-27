<?php

declare(strict_types=1);

namespace App\Actions\Telemetry;

use App\Actions\Audit\RecordAuditEntry;
use App\Data\Telemetry\IssuedIngestToken;
use App\Enums\AuditAction;
use App\Exceptions\StateConflict;
use App\Models\Environment;
use App\Models\IngestToken;
use App\Models\Project;
use App\Models\User;
use App\Services\Telemetry\IngestTokens;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class RotateIngestToken
{
    /**
     * Replaces an ingest token's secret.
     *
     * @param  IngestTokens  $tokens  Issues the replacement.
     * @param  RecordAuditEntry  $audit  Records the rotation.
     */
    public function __construct(private readonly IngestTokens $tokens, private readonly RecordAuditEntry $audit) {}

    /** Replace an active key with a new one (same name and expiry); the old one stops working at once. */
    public function handle(User $actor, IngestToken $token): IssuedIngestToken
    {
        return DB::transaction(function () use ($actor, $token): IssuedIngestToken {
            $environment = Environment::query()->findOrFail($token->environment_id);
            $project = Project::query()->lockForUpdate()->findOrFail($environment->project_id);
            Gate::forUser($actor)->authorize('update', $token);
            $environment = Environment::query()->lockForUpdate()->findOrFail($environment->id);
            $token = $environment->ingestTokens()->lockForUpdate()->findOrFail($token->id);
            StateConflict::unless($token->status() === 'active', __('Only an active key can be replaced.'));
            $issued = $this->tokens->issue($environment, $actor, $token->name, $token->expires_at);
            $token->forceFill(['revoked_at' => now()])->save();
            $this->audit->handle(AuditAction::IngestTokenRotated, $actor, $project->account_id, [
                'name' => $token->name, 'environment' => $environment->name, 'project' => $project->name,
            ], $project->id);

            return $issued;
        });
    }
}
