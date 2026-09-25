<?php

namespace App\Modules\Analytics\Services\Connections;

use App\Core\Contracts\ProjectConnectionDeliveryConsumer;
use App\Core\Data\Connections\ProjectConnectionDeliveryAuthority;
use App\Core\Enums\ProjectConnectionCapability;
use App\Core\Exceptions\Connections\ProjectConnectionDeliveryBlocked;
use App\Core\Services\Connections\ProjectConnectionDeliveryAuthorization;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Models\SiteIncidentAnnotation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ConsumeMonitorIncidentAnnotation implements ProjectConnectionDeliveryConsumer
{
    public const HANDLER = 'analytics.record-incident-annotation.v1';

    public const OPENED_EVENT = 'monitor.incident_opened';

    public const ACKNOWLEDGED_EVENT = 'monitor.incident_acknowledged';

    public const RESOLVED_EVENT = 'monitor.incident_resolved';

    public function __construct(private readonly ProjectConnectionDeliveryAuthorization $authorization) {}

    public function eventTypes(): array
    {
        return [self::OPENED_EVENT, self::ACKNOWLEDGED_EVENT, self::RESOLVED_EVENT];
    }

    public function capability(): ProjectConnectionCapability
    {
        return ProjectConnectionCapability::IncidentAnnotations;
    }

    public function targetProduct(): string
    {
        return 'analytics';
    }

    public function consume(string $deliveryId, string $connectionId, array $payload): void
    {
        $this->handle($deliveryId, $connectionId, $payload);
    }

    /** @param array<string, mixed> $payload */
    public function handle(string $deliveryId, string $connectionId, array $payload): SiteIncidentAnnotation
    {
        $occurredAt = $this->validate($deliveryId, $connectionId, $payload);
        $payloadHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return DB::connection('analytics')->transaction(function () use ($deliveryId, $connectionId, $payload, $payloadHash, $occurredAt): SiteIncidentAnnotation {
            $annotation = SiteIncidentAnnotation::query()
                ->where('delivery_id', $deliveryId)
                ->where('handler', self::HANDLER)
                ->lockForUpdate()
                ->first();

            if ($annotation instanceof SiteIncidentAnnotation) {
                if (! hash_equals($annotation->payload_hash, $payloadHash)) {
                    throw new ProjectConnectionDeliveryBlocked('delivery_payload_conflict');
                }

                return $annotation;
            }

            $this->authorization->assertIncidentAnnotation(
                new ProjectConnectionDeliveryAuthority(
                    deliveryId: $deliveryId,
                    connectionId: $connectionId,
                    capability: 'incident_annotations',
                ),
                (string) $payload['target_site_id'],
            );

            $site = Site::query()->lockForUpdate()->findOrFail($payload['target_site_id']);

            return SiteIncidentAnnotation::query()->create([
                'site_id' => $site->getKey(),
                'delivery_id' => $deliveryId,
                'handler' => self::HANDLER,
                'project_connection_id' => $connectionId,
                'source_incident_id' => $payload['incident_id'],
                'status' => $payload['status'],
                'occurred_at' => $occurredAt,
                'payload_hash' => $payloadHash,
            ]);
        }, attempts: 3);
    }

    /** @param array<string, mixed> $payload */
    private function validate(string $deliveryId, string $connectionId, array $payload): CarbonImmutable
    {
        if (! Str::isUlid($deliveryId)
            || ! Str::isUlid($connectionId)
            || ! is_numeric($payload['target_site_id'] ?? null)
            || ! is_string($payload['incident_id'] ?? null)
            || ! preg_match('/\A[1-9]\d*\z/D', $payload['incident_id'])
            || ! in_array($payload['status'] ?? null, ['open', 'acknowledged', 'resolved'], true)
            || ! is_string($payload['source_environment_id'] ?? null)
            || ! preg_match('/\A[1-9]\d*\z/D', $payload['source_environment_id'])
            || ! is_string($payload['occurred_at'] ?? null)) {
            throw new ProjectConnectionDeliveryBlocked('invalid_event_payload');
        }

        try {
            return CarbonImmutable::parse($payload['occurred_at'])->utc();
        } catch (\Throwable) {
            throw new ProjectConnectionDeliveryBlocked('invalid_event_timestamp');
        }
    }
}
