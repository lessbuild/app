<?php

namespace App\Modules\Deployer\Services;

use App\Modules\Deployer\Models\AlertDestination;
use App\Modules\Deployer\Models\AlertOutboundDelivery;
use App\Modules\Deployer\Models\AlertOutboundDeliveryAttempt;
use App\Modules\Deployer\Models\AlertOutboundDeliveryPayload;
use App\Modules\Deployer\Notifications\AlertEmailNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

final class DeliverAlertWebhookDelivery
{
    private const BACKOFF_SECONDS = [10, 60, 300];

    public function __construct(
        private readonly AlertWebhookTransport $transport,
        private readonly QueueAlertWebhookDelivery $outbox,
    ) {}

    /** Process one generation while allowing duplicate queue messages to observe a single visible delivery row. */
    public function process(string $deliveryId, int $generation): void
    {
        $claim = DB::connection('deployer')->transaction(function () use ($deliveryId, $generation): ?array {
            $delivery = AlertOutboundDelivery::query()->whereKey($deliveryId)->lockForUpdate()->first();
            if ($delivery === null || $delivery->generation !== $generation
                || ! in_array($delivery->status, [AlertOutboundDelivery::STATUS_QUEUED, AlertOutboundDelivery::STATUS_RETRYING], true)) {
                return null;
            }
            if ($delivery->next_attempt_at?->isFuture()) {
                return null;
            }
            if ($delivery->retry_available_until->isPast()) {
                $this->terminal($delivery, AlertOutboundDelivery::STATUS_FAILED, 'retry_window_expired');

                return null;
            }
            if ($delivery->cycle_attempts >= AlertOutboundDelivery::MAX_ATTEMPTS_PER_CYCLE) {
                $this->terminal($delivery, AlertOutboundDelivery::STATUS_FAILED, 'attempt_limit');

                return null;
            }

            $destination = AlertDestination::query()
                ->whereKey($delivery->alert_destination_id)
                ->where('organization_id', $delivery->organization_id)
                ->first();
            if ($destination === null || ! $destination->is_active) {
                $this->terminal($delivery, AlertOutboundDelivery::STATUS_CANCELLED, 'destination_inactive');

                return null;
            }
            if (! in_array($delivery->event, $destination->events ?? [], true)) {
                $this->terminal($delivery, AlertOutboundDelivery::STATUS_CANCELLED, 'event_unsubscribed');

                return null;
            }

            $payloadRecord = AlertOutboundDeliveryPayload::query()->whereKey($delivery->id)->first();
            try {
                $payload = $payloadRecord?->payload;
            } catch (Throwable) {
                $payload = null;
            }
            if (! is_array($payload) || $payloadRecord?->expires_at?->isPast()) {
                $this->terminal($delivery, AlertOutboundDelivery::STATUS_FAILED, 'payload_unavailable');

                return null;
            }

            $attemptNumber = (int) $delivery->attempt_count + 1;
            $token = (string) Str::uuid();
            $delivery->forceFill([
                'status' => AlertOutboundDelivery::STATUS_SENDING,
                'attempt_count' => $attemptNumber,
                'cycle_attempts' => $delivery->cycle_attempts + 1,
                'processing_token' => $token,
                'next_attempt_at' => now('UTC')->addMinutes(15),
                'dispatched_at' => null,
            ])->save();
            AlertOutboundDeliveryAttempt::query()->create([
                'alert_outbound_delivery_id' => $delivery->id,
                'number' => $attemptNumber,
                'status' => AlertOutboundDelivery::STATUS_SENDING,
                'started_at' => now('UTC'),
            ]);

            return [
                'delivery_id' => $delivery->id,
                'destination_id' => $destination->id,
                'organization_id' => $delivery->organization_id,
                'generation' => $generation,
                'token' => $token,
                'payload' => $payload,
            ];
        }, attempts: 3);
        if ($claim === null) {
            return;
        }

        // Re-fetch destination and its current encrypted endpoint/secret immediately before any outbound transport.
        $destination = AlertDestination::query()
            ->whereKey($claim['destination_id'])
            ->where('organization_id', $claim['organization_id'])
            ->first();
        if ($destination === null || ! $destination->is_active) {
            $this->finish($claim, ['status' => 'cancelled', 'error_code' => 'destination_inactive', 'http_status' => null, 'retry_after' => null]);

            return;
        }
        if (! in_array($claim['payload']['event'] ?? null, $destination->events ?? [], true)) {
            $this->finish($claim, ['status' => 'cancelled', 'error_code' => 'event_unsubscribed', 'http_status' => null, 'retry_after' => null]);

            return;
        }

        try {
            if ($destination->type === 'email') {
                Notification::route('mail', $destination->endpoint)->notifyNow(new AlertEmailNotification($claim['payload']));
                $result = ['status' => 'delivered', 'error_code' => null, 'http_status' => null, 'retry_after' => null];
            } else {
                $result = $this->transport->send($destination, $claim['payload']);
            }
        } catch (Throwable) {
            $result = ['status' => 'uncertain', 'error_code' => 'transport_result_unknown', 'http_status' => null, 'retry_after' => null];
        }

        $this->finish($claim, $result);
    }

    /** Complete the claimed attempt, schedule a bounded retry, and retain no response text. */
    private function finish(array $claim, array $result): void
    {
        $retry = null;
        DB::connection('deployer')->transaction(function () use ($claim, $result, &$retry): void {
            $delivery = AlertOutboundDelivery::query()->whereKey($claim['delivery_id'])->lockForUpdate()->first();
            if ($delivery === null || $delivery->generation !== $claim['generation']
                || $delivery->status !== AlertOutboundDelivery::STATUS_SENDING
                || $delivery->processing_token !== $claim['token']) {
                return;
            }

            $status = (string) $result['status'];
            $errorCode = is_string($result['error_code'] ?? null) ? $result['error_code'] : null;
            $httpStatus = is_int($result['http_status'] ?? null) ? $result['http_status'] : null;
            AlertOutboundDeliveryAttempt::query()
                ->where('alert_outbound_delivery_id', $delivery->id)
                ->where('number', $delivery->attempt_count)
                ->whereNull('finished_at')
                ->update([
                    'status' => $status,
                    'error_code' => $errorCode,
                    'http_status' => $httpStatus,
                    'finished_at' => now('UTC'),
                ]);

            if ($status === 'delivered') {
                $this->terminal($delivery, AlertOutboundDelivery::STATUS_DELIVERED, null, $httpStatus);
                AlertDestination::query()->whereKey($delivery->alert_destination_id)->update([
                    'last_delivered_at' => now('UTC'), 'last_failed_at' => null, 'last_error' => null,
                ]);

                return;
            }
            if ($status === 'cancelled') {
                $this->terminal($delivery, AlertOutboundDelivery::STATUS_CANCELLED, $errorCode, $httpStatus);

                return;
            }

            if ($status === 'retrying' && $delivery->cycle_attempts < AlertOutboundDelivery::MAX_ATTEMPTS_PER_CYCLE
                && $delivery->retry_available_until->isFuture()) {
                $delay = is_int($result['retry_after'] ?? null)
                    ? max(1, min(300, $result['retry_after']))
                    : self::BACKOFF_SECONDS[min(count(self::BACKOFF_SECONDS) - 1, max(0, $delivery->cycle_attempts - 1))];
                $nextGeneration = $delivery->generation + 1;
                $delivery->forceFill([
                    'status' => AlertOutboundDelivery::STATUS_RETRYING,
                    'error_code' => $errorCode,
                    'http_status' => $httpStatus,
                    'processing_token' => null,
                    'next_attempt_at' => now('UTC')->addSeconds($delay),
                    'generation' => $nextGeneration,
                    'dispatched_at' => null,
                ])->save();
                AlertDestination::query()->whereKey($delivery->alert_destination_id)->update([
                    'last_failed_at' => now('UTC'), 'last_error' => $errorCode,
                ]);
                $retry = [
                    'destination_id' => (int) $delivery->alert_destination_id,
                    'delivery_id' => (string) $delivery->id,
                    'generation' => $nextGeneration,
                    'delay' => $delay,
                ];

                return;
            }

            $terminalStatus = $status === 'uncertain'
                ? AlertOutboundDelivery::STATUS_UNCERTAIN
                : AlertOutboundDelivery::STATUS_FAILED;
            $terminalCode = $status === 'retrying' && $errorCode === 'provider_retryable' ? 'attempt_limit' : $errorCode;
            $this->terminal($delivery, $terminalStatus, $terminalCode, $httpStatus);
            AlertDestination::query()->whereKey($delivery->alert_destination_id)->update([
                'last_failed_at' => now('UTC'), 'last_error' => $terminalCode,
            ]);
        }, attempts: 3);

        if ($retry !== null) {
            DB::connection('deployer')->afterCommit(function () use ($retry): void {
                try {
                    $this->outbox->dispatchGeneration($retry['delivery_id'], $retry['generation']);
                } catch (Throwable) {
                    // The retrying row stays eligible for the scheduled outbox recovery.
                }
            });
        }
    }

    private function terminal(AlertOutboundDelivery $delivery, string $status, ?string $errorCode, ?int $httpStatus = null): void
    {
        $delivery->forceFill([
            'status' => $status,
            'error_code' => $errorCode,
            'http_status' => $httpStatus,
            'next_attempt_at' => null,
            'processing_token' => null,
            'accepted_at' => $status === AlertOutboundDelivery::STATUS_DELIVERED ? now('UTC') : null,
            'failed_at' => in_array($status, [AlertOutboundDelivery::STATUS_FAILED, AlertOutboundDelivery::STATUS_UNCERTAIN], true) ? now('UTC') : null,
            'dispatched_at' => null,
        ])->save();
    }
}
