<?php

namespace App\Modules\Deployer\Services;

use App\Modules\Deployer\Jobs\DeliverAlertWebhookJob;
use App\Modules\Deployer\Models\AlertDestination;
use App\Modules\Deployer\Models\AlertOutboundDelivery;
use App\Modules\Deployer\Models\AlertOutboundDeliveryAttempt;
use App\Modules\Deployer\Models\AlertOutboundDeliveryPayload;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class QueueAlertWebhookDelivery
{
    /**
     * Persist one encrypted event body and one destination-scoped delivery before dispatching its queue job.
     *
     * @param  array<string, mixed>  $payload
     */
    public function enqueue(AlertDestination $destination, array $payload): ?AlertOutboundDelivery
    {
        [$delivery, $created] = $this->store((int) $destination->id, $payload);
        if ($delivery !== null && $created) {
            // A failed queue push must not abort fan-out to other destinations; the stored row stays eligible for recover().
            DB::connection('deployer')->afterCommit(function () use ($delivery): void {
                try {
                    $this->dispatchGeneration((string) $delivery->id, (int) $delivery->generation);
                } catch (Throwable) {
                    Log::warning('Alert delivery dispatch remains eligible for outbox recovery.', [
                        'delivery_id' => (string) $delivery->id,
                    ]);
                }
            });
        }

        return $delivery;
    }

    /**
     * Materialize legacy serialized jobs on first handling while preserving their destinationId/payload properties.
     *
     * @param  array<string, mixed>  $payload
     */
    public function ensure(int $destinationId, array $payload, ?string $deliveryId = null): ?AlertOutboundDelivery
    {
        if ($deliveryId !== null) {
            return AlertOutboundDelivery::query()
                ->whereKey($deliveryId)
                ->where('alert_destination_id', $destinationId)
                ->first();
        }

        return $this->store($destinationId, $payload)[0];
    }

    /** Dispatch one opaque outbox reference, recording a short queue-push lease for bounded recovery. */
    public function dispatchGeneration(string $deliveryId, int $generation): bool
    {
        $queued = DB::connection('deployer')->transaction(function () use ($deliveryId, $generation): ?array {
            $delivery = AlertOutboundDelivery::query()->whereKey($deliveryId)->lockForUpdate()->first();
            if ($delivery === null || $delivery->generation !== $generation
                || ! in_array($delivery->status, [AlertOutboundDelivery::STATUS_QUEUED, AlertOutboundDelivery::STATUS_RETRYING], true)) {
                return null;
            }
            if ($delivery->dispatched_at?->gt(now('UTC')->subMinutes(10))) {
                return null;
            }
            if ($delivery->alert_destination_id === null) {
                $this->terminal($delivery, AlertOutboundDelivery::STATUS_CANCELLED, 'destination_deleted');

                return null;
            }

            $delivery->forceFill(['dispatched_at' => now('UTC')])->save();

            return [
                'destination_id' => (int) $delivery->alert_destination_id,
                'delivery_id' => (string) $delivery->id,
                'generation' => $generation,
                'delay_until' => $delivery->next_attempt_at?->isFuture() ? $delivery->next_attempt_at : null,
            ];
        }, attempts: 3);
        if ($queued === null) {
            return false;
        }

        try {
            $pending = DeliverAlertWebhookJob::dispatch($queued['destination_id'], [], $queued['delivery_id'], $queued['generation']);
            if ($queued['delay_until'] !== null) {
                $pending->delay($queued['delay_until']);
            }
            unset($pending);
        } catch (Throwable $exception) {
            AlertOutboundDelivery::query()->whereKey($deliveryId)->where('generation', $generation)->update(['dispatched_at' => null]);
            throw $exception;
        }

        return true;
    }

    /** Requeue a bounded batch of due rows and recover sending rows whose worker lease expired. */
    public function recover(int $limit = 100): int
    {
        $limit = max(1, min(500, $limit));
        $cutoff = now('UTC')->subMinutes(10);
        $ids = AlertOutboundDelivery::query()
            ->where(function ($query) use ($cutoff): void {
                $query->where(function ($query) use ($cutoff): void {
                    $query->whereIn('status', [AlertOutboundDelivery::STATUS_QUEUED, AlertOutboundDelivery::STATUS_RETRYING])
                        ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now('UTC')))
                        ->where(fn ($query) => $query->whereNull('dispatched_at')->orWhere('dispatched_at', '<=', $cutoff));
                })->orWhere(function ($query): void {
                    $query->where('status', AlertOutboundDelivery::STATUS_SENDING)
                        ->where('next_attempt_at', '<=', now('UTC'));
                });
            })
            ->orderBy('updated_at')
            ->limit($limit)
            ->pluck('id');

        $recovered = 0;
        foreach ($ids as $id) {
            try {
                $generation = DB::connection('deployer')->transaction(function () use ($id): ?int {
                    $delivery = AlertOutboundDelivery::query()->whereKey($id)->lockForUpdate()->first();
                    if ($delivery === null) {
                        return null;
                    }
                    if ($delivery->status === AlertOutboundDelivery::STATUS_SENDING
                        && $delivery->next_attempt_at?->isPast()) {
                        AlertOutboundDeliveryAttempt::query()
                            ->where('alert_outbound_delivery_id', $delivery->id)
                            ->where('number', $delivery->attempt_count)
                            ->whereNull('finished_at')
                            ->update([
                                'status' => AlertOutboundDelivery::STATUS_UNCERTAIN,
                                'error_code' => 'worker_interrupted',
                                'finished_at' => now('UTC'),
                            ]);
                        $this->terminal($delivery, AlertOutboundDelivery::STATUS_UNCERTAIN, 'worker_interrupted');
                        AlertDestination::query()->whereKey($delivery->alert_destination_id)->update([
                            'last_failed_at' => now('UTC'), 'last_error' => 'worker_interrupted',
                        ]);

                        return null;
                    }

                    return $delivery->generation;
                }, attempts: 3);
                if ($generation !== null && $this->dispatchGeneration((string) $id, $generation)) {
                    $recovered++;
                }
            } catch (Throwable) {
                Log::warning('Alert delivery dispatch remains eligible for outbox recovery.', [
                    'delivery_id' => (string) $id,
                ]);
            }
        }

        return $recovered;
    }

    /** Fail expired rows that have not started a provider request so their encrypted payload can be pruned. */
    public function expireQueued(int $limit = 100): int
    {
        $limit = max(1, min(500, $limit));
        $ids = AlertOutboundDelivery::query()
            ->whereIn('status', [AlertOutboundDelivery::STATUS_QUEUED, AlertOutboundDelivery::STATUS_RETRYING])
            ->where('retry_available_until', '<=', now('UTC'))
            ->orderBy('retry_available_until')
            ->limit($limit)
            ->pluck('id');
        $expired = 0;

        foreach ($ids as $id) {
            $didExpire = DB::connection('deployer')->transaction(function () use ($id): bool {
                $delivery = AlertOutboundDelivery::query()->whereKey($id)->lockForUpdate()->first();
                if ($delivery === null || ! in_array($delivery->status, [
                    AlertOutboundDelivery::STATUS_QUEUED,
                    AlertOutboundDelivery::STATUS_RETRYING,
                ], true) || $delivery->retry_available_until->isFuture()) {
                    return false;
                }

                $this->terminal($delivery, AlertOutboundDelivery::STATUS_FAILED, 'retry_window_expired');
                AlertDestination::query()->whereKey($delivery->alert_destination_id)->update([
                    'last_failed_at' => now('UTC'), 'last_error' => 'retry_window_expired',
                ]);

                return true;
            }, attempts: 3);
            $expired += (int) $didExpire;
        }

        return $expired;
    }

    /** @param array<string, mixed> $payload
     * @return array{0: AlertOutboundDelivery|null, 1: bool}
     */
    private function store(int $destinationId, array $payload): array
    {
        if (! is_string($payload['id'] ?? null) || ! Str::isUuid($payload['id'])) {
            $payload['id'] = (string) Str::uuid();
        }
        if (! is_string($payload['event'] ?? null) || ! in_array($payload['event'], AlertDestination::EVENTS, true)) {
            return [null, false];
        }

        try {
            return DB::connection('deployer')->transaction(function () use ($destinationId, $payload): array {
                $destination = AlertDestination::query()->whereKey($destinationId)->lockForUpdate()->first();
                if ($destination === null || ! $destination->is_active
                    || ! in_array($payload['event'], $destination->events ?? [], true)) {
                    return [null, false];
                }

                $existing = AlertOutboundDelivery::query()
                    ->where('payload_id', $payload['id'])
                    ->where('destination_key', $destination->id)
                    ->first();
                if ($existing !== null) {
                    return [$existing, false];
                }

                $now = now('UTC');
                $delivery = AlertOutboundDelivery::query()->create([
                    'id' => (string) Str::uuid(),
                    'payload_id' => $payload['id'],
                    'destination_key' => $destination->id,
                    'organization_id' => $destination->organization_id,
                    'alert_destination_id' => $destination->id,
                    'destination_type' => $destination->type,
                    'event' => $payload['event'],
                    'status' => AlertOutboundDelivery::STATUS_QUEUED,
                    'attempt_count' => 0,
                    'cycle_attempts' => 0,
                    'manual_retry_count' => 0,
                    'generation' => 0,
                    'retry_available_until' => $now->copy()->addHours(AlertOutboundDelivery::RETRY_WINDOW_HOURS),
                ]);
                AlertOutboundDeliveryPayload::query()->create([
                    'alert_outbound_delivery_id' => $delivery->id,
                    'payload' => $payload,
                    'expires_at' => $delivery->retry_available_until,
                ]);

                return [$delivery, true];
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            $destination = AlertDestination::query()->find($destinationId);
            $existing = $destination === null ? null : AlertOutboundDelivery::query()
                ->where('payload_id', $payload['id'])
                ->where('destination_key', $destination->id)
                ->first();

            return [$existing, false];
        }
    }

    private function terminal(AlertOutboundDelivery $delivery, string $status, string $errorCode): void
    {
        $delivery->forceFill([
            'status' => $status,
            'error_code' => $errorCode,
            'processing_token' => null,
            'next_attempt_at' => null,
            'dispatched_at' => null,
            'failed_at' => in_array($status, [AlertOutboundDelivery::STATUS_FAILED, AlertOutboundDelivery::STATUS_UNCERTAIN], true) ? now('UTC') : null,
        ])->save();
    }
}
