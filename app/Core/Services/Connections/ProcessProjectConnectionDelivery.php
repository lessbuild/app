<?php

namespace App\Core\Services\Connections;

use App\Core\Exceptions\Connections\ProjectConnectionDeliveryBlocked;
use App\Core\Models\ProjectConnection;
use App\Core\Models\ProjectConnectionDelivery;
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

    public function __construct(private readonly ConsumeDeploymentSucceeded $monitorDeployments) {}

    public function process(string $deliveryId): string
    {
        $delivery = DB::connection('core')->transaction(function () use ($deliveryId): ?ProjectConnectionDelivery {
            $delivery = ProjectConnectionDelivery::query()
                ->whereKey($deliveryId)
                ->where('status', 'pending')
                ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
                ->lockForUpdate()
                ->first();

            if ($delivery === null) {
                return null;
            }

            $delivery->forceFill([
                'status' => 'processing',
                'attempts' => $delivery->attempts + 1,
                'last_attempted_at' => now(),
            ])->save();

            return $delivery->refresh();
        });

        if (! $delivery instanceof ProjectConnectionDelivery) {
            return 'skipped';
        }

        try {
            if ($delivery->event_type !== 'deployer.deployment_succeeded' || $delivery->event_version !== 1) {
                throw new ProjectConnectionDeliveryBlocked('unsupported_event');
            }

            if (! config('platform.products.monitor.enabled', false)) {
                throw new ProjectConnectionDeliveryBlocked('target_product_disabled');
            }

            $this->monitorDeployments->handle(
                deliveryId: (string) $delivery->getKey(),
                connectionId: (string) $delivery->project_connection_id,
                payload: (array) $delivery->payload,
            );

            return $this->markDelivered($delivery);
        } catch (Throwable $exception) {
            return $this->markFailure($delivery, $exception);
        }
    }

    private function markDelivered(ProjectConnectionDelivery $delivery): string
    {
        return DB::connection('core')->transaction(function () use ($delivery): string {
            $locked = ProjectConnectionDelivery::query()->whereKey($delivery->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'processing') {
                return $locked->status;
            }

            $locked->forceFill([
                'status' => 'delivered',
                'delivered_at' => now(),
                'available_at' => null,
                'last_error_code' => null,
                'last_error_at' => null,
            ])->save();

            ProjectConnection::query()
                ->whereKey($locked->project_connection_id)
                ->whereNull('disconnected_at')
                ->update([
                    'status' => 'active',
                    'last_succeeded_at' => now(),
                    'last_error_code' => null,
                    'last_error_at' => null,
                    'updated_at' => now(),
                ]);

            return 'delivered';
        });
    }

    private function markFailure(ProjectConnectionDelivery $delivery, Throwable $exception): string
    {
        $disconnected = ProjectConnection::query()
            ->whereKey($delivery->project_connection_id)
            ->where(fn ($query) => $query->where('status', 'disconnected')->orWhereNotNull('disconnected_at'))
            ->exists();

        $blocked = $disconnected
            || $exception instanceof ProjectConnectionDeliveryBlocked
            || $exception instanceof AuthorizationException
            || $exception instanceof ModelNotFoundException
            || $exception instanceof ValidationException
            || ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500);
        $terminal = $blocked || $delivery->attempts >= self::MAX_ATTEMPTS;
        $status = $disconnected ? 'discarded' : ($blocked ? 'blocked' : ($terminal ? 'failed' : 'pending'));
        $errorCode = $disconnected
            ? 'connection_disconnected'
            : ($exception instanceof ProjectConnectionDeliveryBlocked
                ? $exception->reasonCode
                : ($blocked ? 'connection_authorization_failed' : 'target_delivery_failed'));
        $backoffSeconds = min(86400, 60 * (2 ** min(10, max(0, $delivery->attempts - 1))));

        DB::connection('core')->transaction(function () use ($delivery, $status, $errorCode, $terminal, $backoffSeconds): void {
            $locked = ProjectConnectionDelivery::query()->whereKey($delivery->getKey())->lockForUpdate()->first();
            if ($locked === null || $locked->status !== 'processing') {
                return;
            }

            $locked->forceFill([
                'status' => $status,
                'available_at' => $status === 'pending' ? now()->addSeconds($backoffSeconds) : null,
                'last_error_code' => $errorCode,
                'last_error_at' => now(),
            ])->save();

            if ($terminal && $status !== 'discarded') {
                ProjectConnection::query()
                    ->whereKey($locked->project_connection_id)
                    ->whereNull('disconnected_at')
                    ->update([
                        'status' => 'failed',
                        'last_error_code' => $errorCode,
                        'last_error_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
        });

        return $status;
    }
}
