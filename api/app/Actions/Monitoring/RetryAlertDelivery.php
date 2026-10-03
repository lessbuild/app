<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Enums\AlertDeliveryStatus;
use App\Exceptions\StateConflict;
use App\Models\Account;
use App\Models\AlertDelivery;
use App\Models\User;
use App\Services\Monitoring\AlertDeliveryQueue;
use App\Services\Monitoring\AlertDeliveryRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class RetryAlertDelivery
{
    /**
     * Create a new RetryAlertDelivery instance.
     *
     * Resends a failed or uncertain alert delivery.
     *
     * @param  AlertDeliveryQueue  $queue  Locks the delivery.
     * @param  AlertDeliveryRunner  $runner  Cancels any pending attempt and queues a new one.
     */
    public function __construct(private readonly AlertDeliveryQueue $queue, private readonly AlertDeliveryRunner $runner) {}

    /**
     * Start a failed delivery over, with a fresh set of attempts.
     *
     * @param  Account  $account
     * @param  User  $actor
     * @param  AlertDelivery  $delivery
     * @param  int  $generation
     * @return void
     */
    public function handle(Account $account, User $actor, AlertDelivery $delivery, int $generation): void
    {
        DB::transaction(function () use ($account, $actor, $delivery, $generation): void {
            $delivery = $this->queue->lock($delivery->id);
            abort_unless($delivery !== null && $delivery->account_id === $account->id, 404);
            Gate::forUser($actor)->authorize('update', $delivery);
            StateConflict::unless($delivery->generation === $generation && $delivery->status->retryable(), __('This delivery changed or can’t be retried.'));
            $delivery->forceFill(['target_revision' => $delivery->destination->target_revision]);
            StateConflict::unless(! ($this->runner->cancellation($delivery) !== null), __('This destination or incident route is no longer active.'));
            $delivery->forceFill([
                'status' => AlertDeliveryStatus::Queued, 'cycle_attempts' => 0,
                'failed_at' => null, 'accepted_at' => null, 'last_error_code' => null, 'http_status' => null,
                'processing_token' => null, 'next_attempt_at' => now('UTC'),
            ])->save();
            $this->runner->redispatch($delivery);
        }, attempts: 3);
    }
}
