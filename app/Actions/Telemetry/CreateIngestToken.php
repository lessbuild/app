<?php

declare(strict_types=1);

namespace App\Actions\Telemetry;

use App\Actions\Audit\RecordAuditEntry;
use App\Data\Telemetry\IssuedIngestToken;
use App\Enums\AuditAction;
use App\Models\Environment;
use App\Models\IngestToken;
use App\Models\Project;
use App\Models\User;
use App\Services\Telemetry\IngestTokens;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class CreateIngestToken
{
    /**
     * Create a new CreateIngestToken instance.
     *
     * Creates an ingest token for an environment.
     *
     * @param  IngestTokens  $tokens  Generates the token and stores its hash.
     * @param  RecordAuditEntry  $audit  Records the new token.
     */
    public function __construct(private readonly IngestTokens $tokens, private readonly RecordAuditEntry $audit) {}

    /**
     * Create an ingest key for one environment. The secret is returned once; only its hash is kept.
     *
     * @param  User  $actor
     * @param  Environment  $environment
     * @param  string  $name
     * @param  CarbonInterface|null  $expiresAt
     * @return IssuedIngestToken
     */
    public function handle(User $actor, Environment $environment, string $name, ?CarbonInterface $expiresAt = null): IssuedIngestToken
    {
        return DB::transaction(function () use ($actor, $environment, $name, $expiresAt): IssuedIngestToken {
            $project = Project::query()->lockForUpdate()->findOrFail($environment->project_id);
            Gate::forUser($actor)->authorize('create', [IngestToken::class, $project]);
            $environment = Environment::query()->lockForUpdate()->findOrFail($environment->id);
            $issued = $this->tokens->issue($environment, $actor, trim($name), $expiresAt);
            $this->audit->handle(AuditAction::IngestTokenCreated, $actor, $project->account_id, [
                'name' => $issued->token->name, 'environment' => $environment->name, 'project' => $project->name,
            ], $project->id);

            return $issued;
        });
    }
}
