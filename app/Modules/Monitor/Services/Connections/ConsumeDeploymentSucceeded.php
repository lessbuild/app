<?php

namespace App\Modules\Monitor\Services\Connections;

use App\Core\Contracts\ProjectConnectionDeliveryConsumer;
use App\Core\Data\Connections\ProjectConnectionDeliveryAuthority;
use App\Core\Enums\ProjectConnectionCapability;
use App\Core\Services\Connections\ProjectConnectionDeliveryAuthorization;
use App\Modules\Monitor\Models\Deployment;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\ProjectConnectionEventReceipt;
use App\Modules\Monitor\Services\RecordDeployment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;

final class ConsumeDeploymentSucceeded implements ProjectConnectionDeliveryConsumer
{
    public const HANDLER = 'monitor.record-deployment.v1';

    public const EVENT_TYPE = 'deployer.deployment_succeeded';

    public function __construct(
        private readonly RecordDeployment $deployments,
        private readonly ProjectConnectionDeliveryAuthorization $authorization,
    ) {}

    public function eventTypes(): array
    {
        return [self::EVENT_TYPE];
    }

    public function capability(): ProjectConnectionCapability
    {
        return ProjectConnectionCapability::DeploymentContext;
    }

    public function targetProduct(): string
    {
        return 'monitor';
    }

    /** @param array<string, mixed> $payload */
    public function consume(string $deliveryId, string $connectionId, array $payload): void
    {
        $this->handle($deliveryId, $connectionId, $payload);
    }

    /** @param array<string, mixed> $payload */
    public function handle(string $deliveryId, string $connectionId, array $payload): Deployment
    {
        $this->validate($deliveryId, $connectionId, $payload);
        $payloadHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return DB::connection('monitor')->transaction(function () use ($deliveryId, $connectionId, $payload, $payloadHash): Deployment {
            $receipt = ProjectConnectionEventReceipt::query()
                ->where('delivery_id', $deliveryId)
                ->where('handler', self::HANDLER)
                ->lockForUpdate()
                ->first();

            if ($receipt instanceof ProjectConnectionEventReceipt) {
                if (! hash_equals($receipt->payload_hash, $payloadHash)) {
                    throw new LogicException('A project connection delivery ID was reused with different content.');
                }

                return Deployment::query()->findOrFail($receipt->deployment_id);
            }

            $targetEnvironmentId = (string) $payload['target_environment_id'];
            $delivery = $this->authorization->assertDeploymentContext(
                new ProjectConnectionDeliveryAuthority(
                    deliveryId: $deliveryId,
                    connectionId: $connectionId,
                    capability: 'deployment_context',
                ),
                $targetEnvironmentId,
            );

            $environment = Environment::query()
                ->whereKey($targetEnvironmentId)
                ->where('status', 'active')
                ->with('application.workspace')
                ->firstOrFail();
            abort_unless($environment->application?->trashed() === false, 403);
            abort_unless($environment->application?->workspace !== null, 403);

            $recorded = $this->deployments->record(
                environment: $environment,
                data: [
                    'deployment_id' => $payload['deployment_id'],
                    'version' => $payload['version'],
                    'commit_sha' => $payload['revision'],
                    'deployed_at' => $payload['deployed_at'],
                ],
                integration: new ProjectConnectionDeliveryAuthority(
                    deliveryId: (string) $delivery->getKey(),
                    connectionId: $connectionId,
                    capability: 'deployment_context',
                ),
            );

            ProjectConnectionEventReceipt::query()->create([
                'delivery_id' => $deliveryId,
                'project_connection_id' => $connectionId,
                'handler' => self::HANDLER,
                'payload_hash' => $payloadHash,
                'deployment_id' => $recorded->getKey(),
                'processed_at' => now(),
            ]);

            return $recorded;
        }, attempts: 3);
    }

    /** @param array<string, mixed> $payload */
    private function validate(string $deliveryId, string $connectionId, array $payload): void
    {
        if (! Str::isUlid($deliveryId) || ! Str::isUlid($connectionId)
            || ! is_numeric($payload['target_environment_id'] ?? null)
            || ! is_string($payload['deployment_id'] ?? null)
            || ! Str::isUuid($payload['deployment_id'])
            || ! is_string($payload['version'] ?? null)
            || $payload['version'] === ''
            || mb_strlen($payload['version']) > 128
            || ! is_string($payload['deployed_at'] ?? null)
            || ! is_string($payload['source_environment_id'] ?? null)
            || ! is_numeric($payload['source_build_id'] ?? null)
            || ! is_string($payload['revision'] ?? null) && ($payload['revision'] ?? null) !== null) {
            throw new RuntimeException('The deployment event payload is invalid.');
        }
    }
}
