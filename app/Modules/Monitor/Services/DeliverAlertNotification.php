<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Data\Telemetry\AlertDeliveryResult;
use App\Modules\Monitor\Data\Telemetry\AlertDeliveryStatus;
use App\Modules\Monitor\Data\Telemetry\AlertDestinationType;
use App\Modules\Monitor\Models\AlertDelivery;
use App\Modules\Monitor\Models\AlertDeliveryAttempt;
use App\Modules\Monitor\Models\AlertRule;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class DeliverAlertNotification
{
    public function __construct(private readonly AlertDeliveryQueue $queue, private readonly AlertNotificationTransport $transport) {}

    public function process(string $id, int $generation): void
    {
        $claim = DB::connection('monitor')->transaction(function () use ($id, $generation): ?array {
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
                'email' => $destination->type === AlertDestinationType::Email ? $destination->recipient->email : null,
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
        DB::connection('monitor')->transaction(function () use ($id, $generation, $claim, $result): void {
            $delivery = $this->queue->lock($id);
            if ($delivery !== null && $delivery->generation === $generation
                && $delivery->status === AlertDeliveryStatus::Sending && $delivery->processing_token === $claim['token']) {
                $this->finish($delivery, $result);
            }
        }, attempts: 3);
    }

    public function retry(Workspace $workspace, User $actor, AlertDelivery $delivery, int $generation): void
    {
        DB::connection('monitor')->transaction(function () use ($workspace, $actor, $delivery, $generation): void {
            $delivery = $this->queue->lock($delivery->id);
            abort_unless($delivery !== null && $delivery->workspace_id === $workspace->id, 404);
            Gate::forUser($actor)->authorize('update', $delivery);
            abort_unless($delivery->generation === $generation && $delivery->status->retryable(), 409, 'This delivery changed or cannot be retried.');
            $delivery->forceFill(['target_revision' => $delivery->destination?->target_revision]);
            abort_if($this->cancellation($delivery) !== null, 409, 'This destination or incident route is no longer active.');
            $delivery->forceFill([
                'status' => AlertDeliveryStatus::Queued, 'cycle_attempts' => 0,
                'failed_at' => null, 'accepted_at' => null, 'last_error_code' => null, 'http_status' => null,
                'processing_token' => null, 'next_attempt_at' => now('UTC'),
            ])->save();
            $this->redispatch($delivery);
        }, attempts: 3);
    }

    public function interrupted(string $id, int $generation): void
    {
        DB::connection('monitor')->transaction(function () use ($id, $generation): void {
            $delivery = $this->queue->lock($id);
            if ($delivery === null || $delivery->generation !== $generation || ! $delivery->status->pending()) {
                return;
            }
            if ($delivery->status !== AlertDeliveryStatus::Sending) {
                $this->terminal($delivery, AlertDeliveryStatus::Failed, 'worker_interrupted');

                return;
            }
            $this->finish($delivery, new AlertDeliveryResult(
                $delivery->destination?->type === AlertDestinationType::Webhook ? AlertDeliveryStatus::Retrying : AlertDeliveryStatus::Uncertain,
                'worker_interrupted',
            ));
        }, attempts: 3);
    }

    public function recover(int $limit = 100): int
    {
        $ids = AlertDelivery::query()->whereIn('status', ['queued', 'retrying', 'sending'])
            ->where('next_attempt_at', '<=', now('UTC')->format('Y-m-d H:i:s.u'))
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('jobs')
                ->where('jobs.queue', AlertDeliveryQueue::NAME)->whereColumn('jobs.telemetry_uuid', 'alert_deliveries.queue_job_uuid'))
            ->orderBy('next_attempt_at')->orderBy('id')->limit(max(1, min(1000, $limit)))->pluck('id');

        return $ids->filter(fn (string $id): bool => DB::connection('monitor')->transaction(function () use ($id): bool {
            $delivery = $this->queue->lock($id);
            if ($delivery === null || ! $delivery->status->pending() || $delivery->next_attempt_at?->isFuture() || $this->queue->jobExists($delivery)) {
                return false;
            }
            if ($delivery->status === AlertDeliveryStatus::Sending) {
                $this->finish($delivery, new AlertDeliveryResult(
                    $delivery->destination?->type === AlertDestinationType::Webhook ? AlertDeliveryStatus::Retrying : AlertDeliveryStatus::Uncertain,
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

    private function cancellation(AlertDelivery $delivery): ?string
    {
        $destination = $delivery->destination;
        if ($destination === null || $destination->trashed() || ! $destination->enabled || $destination->target_revision !== $delivery->target_revision) {
            return 'destination_changed';
        }
        if ($destination->type === AlertDestinationType::Email && ! $delivery->workspace->members()
            ->whereKey($destination->recipient_user_id)->whereNotNull('email_verified_at')->exists()) {
            return 'recipient_unavailable';
        }
        if ($delivery->event === 'test') {
            return null;
        }
        $incident = $delivery->incident;
        $rule = $incident?->source();
        $environment = $rule?->environment;
        $application = $environment?->application;
        if ($rule === null || $rule->trashed() || ! $rule->enabled || $environment === null || $environment->trashed()
            || $environment->status !== 'active' || $application === null || $application->trashed()
            || $application->workspace_id !== $delivery->workspace_id
            || ($incident->status === 'resolved' && $incident->closure_reason !== 'recovered')) {
            return 'source_unavailable';
        }
        if ($delivery->event === 'escalated' && $incident->status === 'resolved') {
            return 'incident_recovered';
        }
        $routeExists = match ($delivery->event) {
            'opened', 'recovered' => $rule->destinations()->whereKey($destination->id)->wherePivot($delivery->event, true)->exists(),
            'escalated' => $rule instanceof AlertRule && $rule->escalations()->where('alert_destination_id', $destination->id)
                ->where('enabled', true)->exists(),
            default => false,
        };
        if (! $routeExists) {
            return 'route_removed';
        }

        return null;
    }

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

    private function terminal(AlertDelivery $delivery, AlertDeliveryStatus $status, ?string $code): void
    {
        $delivery->forceFill([
            'status' => $status, 'last_error_code' => $code, 'next_attempt_at' => null, 'processing_token' => null,
            'accepted_at' => $status === AlertDeliveryStatus::Accepted ? now('UTC') : null,
            'failed_at' => $status->retryable() ? now('UTC') : null,
        ])->save();
    }

    private function redispatch(AlertDelivery $delivery): void
    {
        $delivery->forceFill(['generation' => $delivery->generation + 1, 'queue_job_uuid' => null])->save();
        $this->queue->dispatch($delivery);
    }
}
