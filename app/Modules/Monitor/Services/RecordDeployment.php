<?php

namespace App\Modules\Monitor\Services;

use App\Core\Data\Connections\ProjectConnectionDeliveryAuthority;
use App\Core\Services\Connections\ProjectConnectionDeliveryAuthorization;
use App\Modules\Monitor\Data\Telemetry\ReleaseIdentity;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Deployment;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\IngestToken;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use App\Modules\Monitor\Services\Telemetry\TelemetryRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

final class RecordDeployment
{
    public function __construct(
        private readonly RecordReleases $releases,
        private readonly TelemetryRedactor $redactor,
        private readonly ProjectConnectionDeliveryAuthorization $connectionAuthorization,
    ) {}

    /** @param array{deployment_id: string, version: string, service?: string|null, service_namespace?: string|null, commit_sha?: string|null, note?: string|null, deployed_at?: string|null} $data */
    public function record(
        Environment $environment,
        array $data,
        ?User $actor = null,
        ?IngestToken $token = null,
        ?ProjectConnectionDeliveryAuthority $integration = null,
    ): Deployment {
        if ((int) ($actor !== null) + (int) ($token !== null) + (int) ($integration !== null) !== 1) {
            throw new LogicException('Exactly one deployment authority is required.');
        }

        return DB::connection('monitor')->transaction(function () use ($environment, $data, $actor, $token, $integration): Deployment {
            if ($actor !== null) {
                $workspaceId = Application::query()->whereKey($environment->application_id)->value('workspace_id');
                $workspace = Workspace::query()->lockForUpdate()->findOrFail($workspaceId);
            }

            $application = Application::query()->lockForUpdate()->find($environment->application_id);
            abort_unless($application !== null, $actor !== null ? 404 : 401, 'This source is unavailable.');
            $environment = $application->environments()->lockForUpdate()->find($environment->id);
            abort_unless($environment !== null, $actor !== null ? 404 : 401, 'This source is unavailable.');
            $environment->setRelation('application', $application);

            if ($actor !== null) {
                $application->setRelation('workspace', $workspace);
                Gate::forUser($actor)->authorize('create', [Deployment::class, $environment]);
            } elseif ($token !== null) {
                $token = $environment->ingestTokens()->active()->lockForUpdate()->find($token->id);
                abort_unless($token !== null && $environment->status === 'active', 401, 'The ingestion token is invalid or inactive.');
            } else {
                abort_unless($integration !== null, 403);
                $this->connectionAuthorization->assertDeploymentContext($integration, (string) $environment->getKey());
            }

            $labels = $this->redactor->redact([
                'version' => $data['version'], 'service' => $data['service'] ?? null, 'service_namespace' => $data['service_namespace'] ?? null,
            ]);
            $identity = ReleaseIdentity::from($labels['version'], $labels['service'], $labels['service_namespace']);

            if ($identity === null) {
                throw ValidationException::withMessages(['version' => 'Use non-secret, non-redacted release labels.']);
            }

            $timestamp = ! empty($data['deployed_at']) ? CarbonImmutable::parse($data['deployed_at'])->utc() : null;
            $commit = ! empty($data['commit_sha']) ? strtolower($data['commit_sha']) : null;
            $note = isset($data['note']) && trim($data['note']) !== '' ? trim($data['note']) : null;
            $fingerprints = $this->fingerprints([$identity->version, $identity->service, $identity->namespace, $commit, $note, $timestamp?->toISOString()]);
            $key = strtolower($data['deployment_id']);
            $existing = $environment->deployments()->where('deployment_key', $key)->first();

            if ($existing !== null) {
                abort_unless(in_array($existing->payload_hash, $fingerprints, true), 409, 'This deployment ID was already used with different details. Retry the original payload unchanged.');

                return $existing->load('release');
            }

            $release = $this->releases->resolve($application->id, $identity);
            $deployment = $environment->deployments()->create([
                'release_id' => $release->id, 'deployment_key' => $key, 'payload_hash' => $fingerprints[0],
                'actor_id' => $actor?->id,
                'ingest_token_id' => $token?->id,
                'source' => $actor !== null ? 'manual' : ($token !== null ? 'api' : 'integration'),
                'commit_sha' => $commit, 'note' => $this->redactor->redact(['note' => $note])['note'],
                'deployed_at' => $timestamp ?? CarbonImmutable::now('UTC'),
            ]);

            return $deployment->setRelation('release', $release);
        }, attempts: 3);
    }

    /** @param list<mixed> $payload
     * @return list<string>
     */
    private function fingerprints(array $payload): array
    {
        $key = config('app.key');

        if (! is_string($key) || $key === '') {
            throw new LogicException('An application key is required for deployment fingerprints.');
        }

        $canonical = 'beacon-deployment-v1:'.json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return array_values(array_map(
            fn (string $key): string => hash_hmac('sha256', $canonical, $key),
            array_unique([$key, ...config('app.previous_keys', [])]),
        ));
    }
}
