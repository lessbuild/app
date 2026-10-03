<?php

declare(strict_types=1);

namespace App\Actions\Telemetry;

use App\Data\Telemetry\ReleaseIdentity;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\IngestToken;
use App\Models\Project;
use App\Models\User;
use App\Services\Monitoring\TelemetryRedactor;
use App\Services\Telemetry\RecordReleases;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

final class RecordDeployment
{
    /**
     * Create a new RecordDeployment instance.
     *
     * Records a deployment reported by a pipeline.
     *
     * @param  RecordReleases  $releases  Finds or creates the release deployed.
     * @param  TelemetryRedactor  $redactor  Redacts the reported labels.
     */
    public function __construct(private readonly RecordReleases $releases, private readonly TelemetryRedactor $redactor) {}

    /**
     * Record that a release was deployed to an environment: by a person (from the UI) or a pipeline (with an ingest key).
     * Retrying with the same deployment ID and details returns the original.
     *
     * @param  Environment  $environment
     * @param  array{deployment_id: string, version: string, service?: string|null, service_namespace?: string|null, commit_sha?: string|null, note?: string|null, deployed_at?: string|null}  $data
     * @param  User|null  $actor
     * @param  IngestToken|null  $token
     * @return Deployment
     */
    public function handle(Environment $environment, array $data, ?User $actor = null, ?IngestToken $token = null): Deployment
    {
        if (($actor === null) === ($token === null)) {
            throw new LogicException('Exactly one deployment authority is required.');
        }

        return DB::transaction(function () use ($environment, $data, $actor, $token): Deployment {
            $project = Project::query()->lockForUpdate()->find($environment->project_id);
            abort_unless($project !== null, $actor !== null ? 404 : 401, 'This source is unavailable.');
            $environment = $project->environments()->lockForUpdate()->find($environment->id);
            abort_unless($environment !== null, $actor !== null ? 404 : 401, 'This source is unavailable.');
            $environment->setRelation('project', $project);

            if ($actor !== null) {
                Gate::forUser($actor)->authorize('manageService', [$project, 'monitoring']);
            } else {
                $token = $token === null ? null : $environment->ingestTokens()->active()->lockForUpdate()->find($token->id);
                abort_unless($token !== null && $project->hasService('monitoring'), 401, 'The ingestion token is invalid or inactive.');
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

            $release = $this->releases->resolve($project->id, $identity);
            $deployment = $environment->deployments()->create([
                'release_id' => $release->id, 'deployment_key' => $key, 'payload_hash' => $fingerprints[0],
                'actor_id' => $actor?->id, 'ingest_token_id' => $token?->id, 'source' => $actor !== null ? 'manual' : 'api',
                'commit_sha' => $commit, 'note' => $this->redactor->redact(['note' => $note])['note'],
                'deployed_at' => $timestamp ?? CarbonImmutable::now('UTC'),
            ]);

            return $deployment->setRelation('release', $release);
        }, attempts: 3);
    }

    /**
     * Fingerprint the request with HMACs under the current and previous app keys, so a retried report is recognised
     * even after a key rotation without storing the request itself.
     *
     * @param  list<mixed>  $payload
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
