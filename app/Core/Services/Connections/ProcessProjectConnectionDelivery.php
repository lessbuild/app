<?php

namespace App\Core\Services\Connections;

use App\Core\Enums\ProjectConnectionCapability;
use App\Core\Exceptions\Connections\ProjectConnectionDeliveryBlocked;
use App\Core\Models\ProjectConnection;
use App\Core\Models\ProjectConnectionDelivery;
use App\Modules\Analytics\Services\Connections\ConsumeDeployerReleaseAnnotation;
use App\Modules\Analytics\Services\Connections\ConsumeMonitorIncidentAnnotation;
use App\Modules\Monitor\Services\Connections\ConsumeDeploymentSucceeded;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ProcessProjectConnectionDelivery
{
    private const MAX_ATTEMPTS = 12;

    public function __construct(
        private readonly ConsumeDeploymentSucceeded $monitorDeployments,
        private readonly ConsumeDeployerReleaseAnnotation $analyticsReleases,
        private readonly ConsumeMonitorIncidentAnnotation $analyticsIncidents,
    ) {}

    public function process(string $deliveryId): string
    {
        $delivery = DB::connection('core')->transaction(function () use ($deliveryId): ProjectConnectionDelivery|string|null {
            $connectionId = ProjectConnectionDelivery::query()->whereKey($deliveryId)->value('project_connection_id');

            if ($connectionId === null) {
                return null;
            }

            $connection = ProjectConnection::query()
                ->whereKey($connectionId)
                ->lockForUpdate()
                ->first();

            if (! $connection instanceof ProjectConnection) {
                return null;
            }

            $delivery = ProjectConnectionDelivery::query()
                ->whereKey($deliveryId)
                ->where('project_connection_id', $connection->getKey())
                ->where('status', 'pending')
                ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
                ->lockForUpdate()
                ->first();

            if ($delivery === null) {
                return null;
            }

            if ($connection->automation_paused_at !== null) {
                return 'paused';
            }

            $delivery->forceFill([
                'status' => 'processing',
                'attempts' => $delivery->attempts + 1,
                'last_attempted_at' => now(),
            ])->save();

            return $delivery->refresh();
        });

        if ($delivery === 'paused') {
            return 'paused';
        }

        if (! $delivery instanceof ProjectConnectionDelivery) {
            return 'skipped';
        }

        try {
            if ($delivery->event_version !== 1 || ! in_array($delivery->event_type, [
                'deployer.deployment_succeeded',
                'monitor.incident_opened',
                'monitor.incident_acknowledged',
                'monitor.incident_resolved',
            ], true)) {
                throw new ProjectConnectionDeliveryBlocked('unsupported_event');
            }

            $connection = ProjectConnection::query()->findOrFail($delivery->project_connection_id);
            $capabilities = (array) $connection->capabilities;

            if ($delivery->event_type === 'deployer.deployment_succeeded'
                && in_array(ProjectConnectionCapability::DeploymentContext->value, $capabilities, true)) {
                if (! config('platform.products.monitor.enabled', false)) {
                    throw new ProjectConnectionDeliveryBlocked('target_product_disabled');
                }

                $this->monitorDeployments->handle(
                    deliveryId: (string) $delivery->getKey(),
                    connectionId: (string) $delivery->project_connection_id,
                    payload: (array) $delivery->payload,
                );
            } elseif ($delivery->event_type === 'deployer.deployment_succeeded'
                && in_array(ProjectConnectionCapability::ReleaseAnnotations->value, $capabilities, true)) {
                if (! config('platform.products.analytics.enabled', false)) {
                    throw new ProjectConnectionDeliveryBlocked('target_product_disabled');
                }

                $this->analyticsReleases->handle(
                    deliveryId: (string) $delivery->getKey(),
                    connectionId: (string) $delivery->project_connection_id,
                    payload: (array) $delivery->payload,
                );
            } elseif (str_starts_with($delivery->event_type, 'monitor.incident_')
                && in_array(ProjectConnectionCapability::IncidentAnnotations->value, $capabilities, true)) {
                if (! config('platform.products.analytics.enabled', false)) {
                    throw new ProjectConnectionDeliveryBlocked('target_product_disabled');
                }

                $this->analyticsIncidents->handle(
                    deliveryId: (string) $delivery->getKey(),
                    connectionId: (string) $delivery->project_connection_id,
                    payload: (array) $delivery->payload,
                );
            } else {
                throw new ProjectConnectionDeliveryBlocked('unsupported_capability');
            }

            return $this->markDelivered($delivery);
        } catch (Throwable $exception) {
            return $this->markFailure($delivery, $exception);
        }
    }

    private function markDelivered(ProjectConnectionDelivery $delivery): string
    {
        return DB::connection('core')->transaction(function () use ($delivery): string {
            $connectionId = ProjectConnectionDelivery::query()
                ->whereKey($delivery->getKey())
                ->value('project_connection_id');

            if ($connectionId === null) {
                return 'skipped';
            }

            $connection = ProjectConnection::query()
                ->whereKey($connectionId)
                ->lockForUpdate()
                ->first();

            if (! $connection instanceof ProjectConnection) {
                return 'skipped';
            }

            $locked = ProjectConnectionDelivery::query()
                ->whereKey($delivery->getKey())
                ->where('project_connection_id', $connection->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->status !== 'processing') {
                return $locked?->status ?? 'skipped';
            }

            $locked->forceFill([
                'status' => 'delivered',
                'delivered_at' => now(),
                'available_at' => null,
                'last_error_code' => null,
                'last_error_at' => null,
            ])->save();

            if ($connection->disconnected_at === null) {
                $connection->forceFill([
                    'status' => 'active',
                    'last_succeeded_at' => now(),
                    'last_error_code' => null,
                    'last_error_at' => null,
                ])->save();
            }

            return 'delivered';
        });
    }

    private function markFailure(ProjectConnectionDelivery $delivery, Throwable $exception): string
    {
        $backoffSeconds = min(86400, 60 * (2 ** min(10, max(0, $delivery->attempts - 1))));

        return DB::connection('core')->transaction(function () use ($delivery, $exception, $backoffSeconds): string {
            $connectionId = ProjectConnectionDelivery::query()
                ->whereKey($delivery->getKey())
                ->value('project_connection_id');

            if ($connectionId === null) {
                return 'skipped';
            }

            $connection = ProjectConnection::query()
                ->whereKey($connectionId)
                ->lockForUpdate()
                ->first();

            if (! $connection instanceof ProjectConnection) {
                return 'skipped';
            }

            $locked = ProjectConnectionDelivery::query()
                ->whereKey($delivery->getKey())
                ->where('project_connection_id', $connection->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->status !== 'processing') {
                return $locked?->status ?? 'skipped';
            }

            $disconnected = $connection->status === 'disconnected' || $connection->disconnected_at !== null;
            $blocked = $disconnected
                || $exception instanceof ProjectConnectionDeliveryBlocked
                || $exception instanceof AuthorizationException
                || $exception instanceof ModelNotFoundException
                || $exception instanceof ValidationException
                || ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500);
            $terminal = $blocked || $locked->attempts >= self::MAX_ATTEMPTS;
            $status = $disconnected ? 'discarded' : ($blocked ? 'blocked' : ($terminal ? 'failed' : 'pending'));
            $errorCode = $disconnected
                ? 'connection_disconnected'
                : ($exception instanceof ProjectConnectionDeliveryBlocked
                    ? $exception->reasonCode
                    : ($blocked ? 'connection_authorization_failed' : 'target_delivery_failed'));

            $locked->forceFill([
                'status' => $status,
                'available_at' => $status === 'pending' ? now()->addSeconds($backoffSeconds) : null,
                'last_error_code' => $errorCode,
                'last_error_at' => now(),
            ])->save();

            if ($terminal && ! $disconnected && $errorCode !== 'automation_paused') {
                $connection->forceFill([
                    'status' => 'failed',
                    'last_error_code' => $errorCode,
                    'last_error_at' => now(),
                ])->save();
            }

            return $status;
        });
    }
}
