<?php

namespace App\Modules\Deployer\Jobs;

use App\Modules\Deployer\Models\AlertDestination;
use App\Modules\Deployer\Models\AlertOutboundDelivery;
use App\Modules\Deployer\Models\AlertOutboundDeliveryAttempt;
use App\Modules\Deployer\Services\DeliverAlertWebhookDelivery;
use App\Modules\Deployer\Services\QueueAlertWebhookDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class DeliverAlertWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public ?string $deliveryId = null;

    public int $generation = 0;

    /**
     * Capture the alert destination and event payload for asynchronous delivery.
     *
     * @param  array<string, mixed>  $payload  Legacy-compatible queued event data; the authoritative retry copy is encrypted in the outbox.
     * @param  int  $destinationId  Alert destination identifier reloaded before applying event subscriptions.
     */
    public function __construct(public int $destinationId, public array $payload, ?string $deliveryId = null, int $generation = 0)
    {
        $this->deliveryId = $deliveryId;
        $this->generation = $generation;
    }

    /**
     * Process the durable destination-scoped delivery while preserving queued jobs serialized before the outbox migration.
     */
    public function handle(?QueueAlertWebhookDelivery $outbox = null, ?DeliverAlertWebhookDelivery $deliveries = null): void
    {
        $outbox ??= app(QueueAlertWebhookDelivery::class);
        $deliveries ??= app(DeliverAlertWebhookDelivery::class);
        $delivery = $outbox->ensure($this->destinationId, $this->payload, $this->deliveryId);
        if ($delivery === null) {
            return;
        }
        if ($this->deliveryId === null && $delivery->generation !== 0) {
            return;
        }
        $this->deliveryId = (string) $delivery->id;
        $deliveries->process($this->deliveryId, $this->generation);
    }

    /**
     * Record a fixed sanitized failure code when the queue exhausts this alert job.
     *
     * @param  \Throwable  $exception  Failure delivered by the queue after this job cannot complete successfully.
     */
    public function failed(\Throwable $exception): void
    {
        $query = AlertOutboundDelivery::query()->where('alert_destination_id', $this->destinationId);
        if ($this->deliveryId !== null) {
            $query->whereKey($this->deliveryId);
        } elseif (is_string($this->payload['id'] ?? null)) {
            $query->where('payload_id', $this->payload['id']);
        } else {
            AlertDestination::query()->whereKey($this->destinationId)->update([
                'last_failed_at' => now('UTC'), 'last_error' => 'worker_failed',
            ]);

            return;
        }
        $delivery = $query->first();
        if ($delivery === null) {
            return;
        }

        DB::connection('deployer')->transaction(function () use ($delivery): void {
            $locked = AlertOutboundDelivery::query()->whereKey($delivery->id)->lockForUpdate()->first();
            if ($locked === null || in_array($locked->status, [
                AlertOutboundDelivery::STATUS_DELIVERED,
                AlertOutboundDelivery::STATUS_CANCELLED,
                AlertOutboundDelivery::STATUS_FAILED,
                AlertOutboundDelivery::STATUS_UNCERTAIN,
            ], true) || $locked->generation !== $this->generation) {
                return;
            }
            $wasSending = $locked->status === AlertOutboundDelivery::STATUS_SENDING;
            $terminalStatus = $wasSending ? AlertOutboundDelivery::STATUS_UNCERTAIN : AlertOutboundDelivery::STATUS_FAILED;
            $errorCode = $wasSending ? 'worker_interrupted' : 'worker_failed';
            AlertOutboundDeliveryAttempt::query()
                ->where('alert_outbound_delivery_id', $locked->id)
                ->where('number', $locked->attempt_count)
                ->whereNull('finished_at')
                ->update([
                    'status' => $terminalStatus,
                    'error_code' => $errorCode,
                    'finished_at' => now('UTC'),
                ]);
            $locked->forceFill([
                'status' => $terminalStatus,
                'error_code' => $errorCode,
                'processing_token' => null,
                'next_attempt_at' => null,
                'failed_at' => now('UTC'),
            ])->save();
            AlertDestination::query()->whereKey($locked->alert_destination_id)->update([
                'last_failed_at' => now('UTC'), 'last_error' => $errorCode,
            ]);
        }, attempts: 3);
    }
}
