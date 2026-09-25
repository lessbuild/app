<?php

namespace App\Modules\Analytics\Services\Connections;

use App\Core\Contracts\ProjectConnectionDeliveryConsumer;
use App\Core\Data\Connections\ProjectConnectionDeliveryAuthority;
use App\Core\Enums\ProjectConnectionCapability;
use App\Core\Exceptions\Connections\ProjectConnectionDeliveryBlocked;
use App\Core\Services\Connections\ProjectConnectionDeliveryAuthorization;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Models\SiteReleaseAnnotation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ConsumeDeployerReleaseAnnotation implements ProjectConnectionDeliveryConsumer
{
    public const HANDLER = 'analytics.record-release-annotation.v1';

    public const EVENT_TYPE = 'deployer.deployment_succeeded';

    public function __construct(private readonly ProjectConnectionDeliveryAuthorization $authorization) {}

    public function eventTypes(): array
    {
        return [self::EVENT_TYPE];
    }

    public function capability(): ProjectConnectionCapability
    {
        return ProjectConnectionCapability::ReleaseAnnotations;
    }

    public function targetProduct(): string
    {
        return 'analytics';
    }

    /** @param array<string, mixed> $payload */
    public function consume(string $deliveryId, string $connectionId, array $payload): void
    {
        $this->handle($deliveryId, $connectionId, $payload);
    }

    /** @param array<string, mixed> $payload */
    public function handle(string $deliveryId, string $connectionId, array $payload): SiteReleaseAnnotation
    {
        $deployedAt = $this->validate($deliveryId, $connectionId, $payload);
        $payloadHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return DB::connection('analytics')->transaction(function () use ($deliveryId, $connectionId, $payload, $payloadHash, $deployedAt): SiteReleaseAnnotation {
            $annotation = SiteReleaseAnnotation::query()
                ->where('delivery_id', $deliveryId)
                ->where('handler', self::HANDLER)
                ->lockForUpdate()
                ->first();

            if ($annotation instanceof SiteReleaseAnnotation) {
                if (! hash_equals($annotation->payload_hash, $payloadHash)) {
                    throw new ProjectConnectionDeliveryBlocked('delivery_payload_conflict');
                }

                return $annotation;
            }

            $this->authorization->assertReleaseAnnotation(
                new ProjectConnectionDeliveryAuthority(
                    deliveryId: $deliveryId,
                    connectionId: $connectionId,
                    capability: 'release_annotations',
                ),
                (string) $payload['target_site_id'],
            );

            $site = Site::query()->lockForUpdate()->findOrFail($payload['target_site_id']);

            return SiteReleaseAnnotation::query()->create([
                'site_id' => $site->getKey(),
                'delivery_id' => $deliveryId,
                'handler' => self::HANDLER,
                'project_connection_id' => $connectionId,
                'deployment_id' => $payload['deployment_id'],
                'source_build_id' => (string) $payload['source_build_id'],
                'version' => $payload['version'],
                'revision' => $payload['revision'] ?? null,
                'deployed_at' => $deployedAt,
                'payload_hash' => $payloadHash,
            ]);
        }, attempts: 3);
    }

    /** @param array<string, mixed> $payload */
    private function validate(string $deliveryId, string $connectionId, array $payload): CarbonImmutable
    {
        $validRevision = ($payload['revision'] ?? null) === null
            || (is_string($payload['revision']) && preg_match('/\A[a-f0-9]{7,64}\z/iD', $payload['revision']) === 1);

        if (! Str::isUlid($deliveryId)
            || ! Str::isUlid($connectionId)
            || ! is_numeric($payload['target_site_id'] ?? null)
            || ! is_string($payload['deployment_id'] ?? null)
            || ! Str::isUuid($payload['deployment_id'])
            || ! is_numeric($payload['source_build_id'] ?? null)
            || ! is_string($payload['source_environment_id'] ?? null)
            || ! is_string($payload['version'] ?? null)
            || trim($payload['version']) === ''
            || mb_strlen($payload['version']) > 128
            || ! $validRevision
            || ! is_string($payload['deployed_at'] ?? null)) {
            throw new ProjectConnectionDeliveryBlocked('invalid_event_payload');
        }

        try {
            return CarbonImmutable::parse($payload['deployed_at'])->utc();
        } catch (\Throwable) {
            throw new ProjectConnectionDeliveryBlocked('invalid_event_timestamp');
        }
    }
}
