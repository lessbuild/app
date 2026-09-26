<?php

namespace App\Modules\Deployer\Actions\Observability;

use App\Modules\Deployer\Models\AlertDestination;
use App\Modules\Deployer\Models\AlertOutboundDelivery;
use App\Modules\Deployer\Models\AlertOutboundDeliveryPayload;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\Entitlements;
use App\Modules\Deployer\Services\QueueAlertWebhookDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

class RetryAlertOutboundDeliveryAction
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly QueueAlertWebhookDelivery $deliveries,
    ) {}

    /** Retry one failed outbound alert once using its original encrypted event body and the destination's current configuration. */
    public function handle(AlertOutboundDelivery $delivery, User $actor): void
    {
        Gate::forUser($actor)->authorize('retry', $delivery);
        $this->entitlements->enforce($delivery->organization, 'alerts');
        $queued = DB::connection('deployer')->transaction(function () use ($delivery, $actor): ?array {
            $current = AlertOutboundDelivery::query()->whereKey($delivery->id)->lockForUpdate()->first();
            abort_unless($current !== null && (int) $current->organization_id === (int) $actor->current_organization_id, 404);
            abort_unless($current->status === AlertOutboundDelivery::STATUS_FAILED
                && $current->manual_retry_count < AlertOutboundDelivery::MAX_MANUAL_RETRIES
                && $current->retry_available_until->isFuture(), 409, 'This delivery has changed or its retry window has closed.');

            $destination = AlertDestination::query()
                ->whereKey($current->alert_destination_id)
                ->where('organization_id', $current->organization_id)
                ->lockForUpdate()
                ->first();
            abort_unless($destination !== null && $destination->is_active
                && in_array($current->event, $destination->events ?? [], true), 409, 'This destination is no longer active for the event.');

            $payloadRecord = AlertOutboundDeliveryPayload::query()->whereKey($current->id)->first();
            try {
                $payload = $payloadRecord?->payload;
            } catch (Throwable) {
                $payload = null;
            }
            abort_unless(is_array($payload) && $payloadRecord?->expires_at?->isFuture(), 409, 'The original delivery payload is no longer available.');

            $generation = $current->generation + 1;
            $current->forceFill([
                'status' => AlertOutboundDelivery::STATUS_QUEUED,
                'cycle_attempts' => 0,
                'manual_retry_count' => $current->manual_retry_count + 1,
                'generation' => $generation,
                'processing_token' => null,
                'next_attempt_at' => now('UTC'),
                'accepted_at' => null,
                'failed_at' => null,
                'error_code' => null,
                'http_status' => null,
                'dispatched_at' => null,
            ])->save();

            return [
                'delivery_id' => (string) $current->id,
                'generation' => $generation,
            ];
        }, attempts: 3);

        if ($queued !== null) {
            DB::connection('deployer')->afterCommit(fn () => $this->deliveries->dispatchGeneration($queued['delivery_id'], $queued['generation']));
        }
    }
}
