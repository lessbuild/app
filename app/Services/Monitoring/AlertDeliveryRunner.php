<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Data\Monitoring\AlertDeliveryResult;
use App\Enums\AlertDeliveryStatus;
use App\Enums\AlertDestinationType;
use App\Models\AlertDelivery;
use App\Models\AlertDeliveryAttempt;
use App\Models\AlertDestination;
use App\Models\AlertRule;
use App\Models\OnCallSchedule;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Sends queued alert deliveries, retries them with backoff, and recovers ones whose jobs went missing. */
final class AlertDeliveryRunner
{
    /**
     * Create a new AlertDeliveryRunner instance.
     *
     * Sends alert deliveries.
     *
     * @param  AlertDeliveryQueue  $queue  Locks deliveries and queues their jobs.
     * @param  AlertNotificationTransport  $transport  Sends to the destination.
     * @param  OnCall  $onCall  Finds who's on call for destinations that follow a schedule.
     */
    public function __construct(private readonly AlertDeliveryQueue $queue, private readonly AlertNotificationTransport $transport, private readonly OnCall $onCall) {}

    /**
     * Send one delivery attempt: claims it under lock (skipping stale generations and deliveries not yet due,
     * cancelling ones no longer wanted, holding a recovery until its opening alert is out, failing ones past their
     * attempt limit), sends outside the transaction, then records the result if the claim still holds.
     *
     * @param  string  $id
     * @param  int  $generation
     * @return void
     */
    public function process(string $id, int $generation): void
    {
        $claim = DB::transaction(function () use ($id, $generation): ?array {
            $delivery = $this->queue->lock($id);
            if ($delivery === null || $delivery->generation !== $generation
                || ! in_array($delivery->status, [AlertDeliveryStatus::Queued, AlertDeliveryStatus::Retrying], true)) {
                return null;
            }
            if ($delivery->next_attempt_at?->isFuture()) {
                $this->redispatch($delivery);

                return null;
            }
            if ($reason = $this->cancellation($delivery)) {
                $this->terminal($delivery, AlertDeliveryStatus::Cancelled, $reason);

                return null;
            }
            if ($delivery->event === 'recovered' && AlertDelivery::query()->where('incident_id', $delivery->incident_id)
                ->where('alert_destination_id', $delivery->alert_destination_id)->where('event', 'opened')
                ->whereIn('status', ['queued', 'retrying', 'sending'])->exists()) {
                $delivery->forceFill(['next_attempt_at' => now('UTC')->addSeconds(30)])->save();
                $this->redispatch($delivery);

                return null;
            }
            if ($delivery->cycle_attempts >= AlertDeliveryQueue::MAX_ATTEMPTS) {
                $this->terminal($delivery, AlertDeliveryStatus::Failed, 'attempt_limit');

                return null;
            }
            $destination = $delivery->destination;
            $target = [
                'type' => $destination->type, 'endpoint' => $destination->endpoint_url, 'secret' => $destination->signing_secret,
                'email' => $destination->type === AlertDestinationType::Email ? $this->recipient($destination)?->email : null,
                'user' => $destination->type === AlertDestinationType::Push ? $this->recipient($destination)?->id : null,
            ];
            $payload = $delivery->payload;
            $token = (string) Str::uuid();
            $delivery->forceFill([
                'status' => AlertDeliveryStatus::Sending, 'processing_token' => $token,
                'attempt_count' => $delivery->attempt_count + 1, 'cycle_attempts' => $delivery->cycle_attempts + 1,
                'next_attempt_at' => now('UTC')->addSeconds(AlertDeliveryQueue::LEASE_SECONDS),
            ])->save();
            $attempt = new AlertDeliveryAttempt;
            $attempt->forceFill([
                'alert_delivery_id' => $delivery->id, 'number' => $delivery->attempt_count,
                'status' => AlertDeliveryStatus::Sending, 'started_at' => now('UTC'),
            ])->save();

            return compact('target', 'payload', 'token');
        }, attempts: 3);
        if ($claim === null) {
            return;
        }

        $result = $this->transport->send($id, $claim['target'], $claim['payload']);
        DB::transaction(function () use ($id, $generation, $claim, $result): void {
            $delivery = $this->queue->lock($id);
            if ($delivery !== null && $delivery->generation === $generation
                && $delivery->status === AlertDeliveryStatus::Sending && $delivery->processing_token === $claim['token']) {
                $this->finish($delivery, $result);
            }
        }, attempts: 3);
    }

    /**
     * Settle a delivery whose worker died: unstarted ones fail, and one that was sending is retried for webhooks
     * (receivers can deduplicate) or marked uncertain for others.
     *
     * @param  string  $id
     * @param  int  $generation
     * @return void
     */
    public function interrupted(string $id, int $generation): void
    {
        DB::transaction(function () use ($id, $generation): void {
            $delivery = $this->queue->lock($id);
            if ($delivery === null || $delivery->generation !== $generation || ! $delivery->status->pending()) {
                return;
            }
            if ($delivery->status !== AlertDeliveryStatus::Sending) {
                $this->terminal($delivery, AlertDeliveryStatus::Failed, 'worker_interrupted');

                return;
            }
            $this->finish($delivery, new AlertDeliveryResult(
                $delivery->destination->type === AlertDestinationType::Webhook ? AlertDeliveryStatus::Retrying : AlertDeliveryStatus::Uncertain,
                'worker_interrupted',
            ));
        }, attempts: 3);
    }

    /**
     * Settle or requeues up to `$limit` due deliveries whose jobs have gone missing, and returns how many.
     *
     * @param  int  $limit
     * @return int
     */
    public function recover(int $limit = 100): int
    {
        $ids = AlertDelivery::query()->whereIn('status', ['queued', 'retrying', 'sending'])
            ->where('next_attempt_at', '<=', now('UTC')->format('Y-m-d H:i:s.u'))
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('jobs')
                ->where('jobs.queue', AlertDeliveryQueue::NAME)->whereColumn('jobs.job_uuid', 'alert_deliveries.queue_job_uuid'))
            ->orderBy('next_attempt_at')->orderBy('id')->limit(max(1, min(1000, $limit)))->pluck('id');

        return $ids->filter(fn (string $id): bool => DB::transaction(function () use ($id): bool {
            $delivery = $this->queue->lock($id);
            if ($delivery === null || ! $delivery->status->pending() || $delivery->next_attempt_at?->isFuture() || $this->queue->jobExists($delivery)) {
                return false;
            }
            if ($delivery->status === AlertDeliveryStatus::Sending) {
                $this->finish($delivery, new AlertDeliveryResult(
                    $delivery->destination->type === AlertDestinationType::Webhook ? AlertDeliveryStatus::Retrying : AlertDeliveryStatus::Uncertain,
                    'worker_interrupted',
                ));
            } elseif ($reason = $this->cancellation($delivery)) {
                $this->terminal($delivery, AlertDeliveryStatus::Cancelled, $reason);
            } else {
                $this->redispatch($delivery);
            }

            return true;
        }, attempts: 3))->count();
    }

    /**
     * Explain why a delivery should no longer be sent, or return null when it still should.
     *
     * @param  AlertDelivery  $delivery
     * @return string|null
     */
    public function cancellation(AlertDelivery $delivery): ?string
    {
        $destination = $delivery->destination;
        if ($destination->trashed() || ! $destination->enabled || $destination->target_revision !== $delivery->target_revision) {
            return 'destination_changed';
        }
        if ($destination->type->followsPerson()) {
            $recipient = $this->recipient($destination);
            if ($recipient === null || ! $delivery->account->members()->whereKey($recipient->id)->whereNotNull('email_verified_at')->exists()) {
                return 'recipient_unavailable';
            }
        }
        // Test alerts and deploy notifications aren't about an incident: the destination checks above are all they need.
        if ($delivery->event === 'test' || str_starts_with($delivery->event, 'deploy_')) {
            return null;
        }
        $incident = $delivery->incident;
        $rule = $incident?->source();
        $environment = $rule?->environment;
        $project = $environment?->project;
        if ($incident === null || $rule === null || $rule->trashed() || ! $rule->enabled || $environment === null
            || $project === null || $project->account_id !== $delivery->account_id
            || ($incident->status === 'resolved' && $incident->closure_reason !== 'recovered')) {
            return 'source_unavailable';
        }
        if ($delivery->event === 'escalated' && $incident->status === 'resolved') {
            return 'incident_recovered';
        }
        $routeExists = match ($delivery->event) {
            'opened', 'recovered' => $rule->destinations()->whereKey($destination->id)->wherePivot($delivery->event, true)->exists(),
            'escalated' => $rule instanceof AlertRule && $rule->escalations()->where('alert_destination_id', $destination->id)->where('enabled', true)->exists(),
            default => false,
        };
        if (! $routeExists) {
            return 'route_removed';
        }

        return null;
    }

    /**
     * Record an attempt's result: retryable results are retried with backoff (or the destination's Retry-After) until
     * the attempt limit, anything else is final.
     *
     * @param  AlertDelivery  $delivery
     * @param  AlertDeliveryResult  $result
     * @return void
     */
    private function finish(AlertDelivery $delivery, AlertDeliveryResult $result): void
    {
        $delivery->attempts()->where('number', $delivery->attempt_count)->whereNull('finished_at')->update([
            'status' => $result->status->value, 'error_code' => $result->errorCode,
            'http_status' => $result->httpStatus, 'finished_at' => now('UTC'),
        ]);
        $delivery->forceFill(['http_status' => $result->httpStatus]);
        if ($result->status === AlertDeliveryStatus::Retrying && $delivery->cycle_attempts < AlertDeliveryQueue::MAX_ATTEMPTS) {
            if ($reason = $this->cancellation($delivery)) {
                $this->terminal($delivery, AlertDeliveryStatus::Cancelled, $reason);

                return;
            }
            $delay = $result->retryAfter ?? AlertDeliveryQueue::BACKOFF[min(3, max(0, $delivery->cycle_attempts - 1))];
            $delivery->forceFill([
                'status' => AlertDeliveryStatus::Retrying, 'last_error_code' => $result->errorCode,
                'processing_token' => null, 'next_attempt_at' => now('UTC')->addSeconds($delay),
            ])->save();
            $this->redispatch($delivery);

            return;
        }
        $this->terminal($delivery, $result->status === AlertDeliveryStatus::Retrying ? AlertDeliveryStatus::Failed : $result->status, $result->errorCode);
    }

    /**
     * End a delivery in a final status with its error code.
     *
     * @param  AlertDelivery  $delivery
     * @param  AlertDeliveryStatus  $status
     * @param  string|null  $code
     * @return void
     */
    private function terminal(AlertDelivery $delivery, AlertDeliveryStatus $status, ?string $code): void
    {
        $delivery->forceFill([
            'status' => $status, 'last_error_code' => $code, 'next_attempt_at' => null, 'processing_token' => null,
            'accepted_at' => $status === AlertDeliveryStatus::Accepted ? now('UTC') : null,
            'failed_at' => $status->retryable() ? now('UTC') : null,
        ])->save();
    }

    /**
     * Queue the delivery again under a new generation, so any older job for it does nothing.
     *
     * @param  AlertDelivery  $delivery
     * @return void
     */
    public function redispatch(AlertDelivery $delivery): void
    {
        $delivery->forceFill(['generation' => $delivery->generation + 1, 'queue_job_uuid' => null])->save();
        $this->queue->dispatch($delivery);
    }

    /**
     * Get who an email or push destination sends to right now: whoever is on call in its schedule, or its fixed recipient.
     *
     * @param  AlertDestination  $destination
     * @return User|null
     */
    private function recipient(AlertDestination $destination): ?User
    {
        if ($destination->on_call_schedule_id !== null) {
            $schedule = OnCallSchedule::query()->where('account_id', $destination->account_id)->find($destination->on_call_schedule_id);

            return $schedule === null ? null : $this->onCall->current($schedule);
        }

        return $destination->recipient;
    }
}
