<?php

namespace App\Core\Console\Commands;

use App\Core\Models\ProjectConnectionDelivery;
use App\Core\Services\Connections\DispatchDeploymentSucceededOutboxEvent;
use App\Core\Services\Connections\DispatchMonitorIncidentOutboxEvent;
use App\Core\Services\Connections\ProcessProjectConnectionDelivery;
use App\Modules\Deployer\Models\DeploymentSucceededOutboxEvent;
use App\Modules\Monitor\Models\ProjectConnectionIncidentOutboxEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class DeliverProjectConnectionEventsCommand extends Command
{
    protected $signature = 'project-connections:deliver
        {--limit=100 : Maximum source and target events to process (1-1000)}
        {--retry-failed : Requeue one failed source event; requires --source and --event-id}
        {--source= : Failed source product: deployer or monitor}
        {--event-id= : Failed source outbox event ULID; requires --retry-failed and --source}';

    protected $description = 'Dispatch durable project connection events and deliver due integrations';

    public function handle(
        DispatchDeploymentSucceededOutboxEvent $dispatch,
        DispatchMonitorIncidentOutboxEvent $monitorDispatch,
        ProcessProjectConnectionDelivery $deliveries,
    ): int {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false) {
            $this->error('The limit must be an integer between 1 and 1000.');

            return self::INVALID;
        }

        $source = $this->option('source');
        $source = is_string($source) && trim($source) !== '' ? strtolower(trim($source)) : null;
        $eventId = $this->option('event-id');
        $eventId = is_string($eventId) && trim($eventId) !== '' ? strtolower(trim($eventId)) : null;
        $retryFailed = (bool) $this->option('retry-failed');

        if ($source !== null && ! in_array($source, ['deployer', 'monitor'], true)) {
            $this->error('The source must be deployer or monitor.');

            return self::INVALID;
        }

        if ($retryFailed && ($source === null || $eventId === null
            || ! preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $eventId))) {
            $this->error('--retry-failed requires a valid --source and 26-character --event-id ULID.');

            return self::INVALID;
        }

        if (! $retryFailed && ($source !== null || $eventId !== null)) {
            $this->error('--source and --event-id can only be used with --retry-failed.');

            return self::INVALID;
        }

        if ($retryFailed && $source === 'monitor' && ! config('platform.products.monitor.enabled', false)) {
            $this->error('Enable the Monitor module before retrying Monitor incident events.');

            return self::FAILURE;
        }

        if (! Schema::connection('core')->hasTable('project_connection_deliveries')
            || ! Schema::connection('deployer')->hasTable('deployment_succeeded_outbox_events')) {
            $this->error('Run the Core and Deployer module migrations before delivering project connection events.');

            return self::FAILURE;
        }

        if (config('platform.products.monitor.enabled', false)
            && (! Schema::connection('monitor')->hasTable('project_connection_event_receipts')
                || ! Schema::connection('monitor')->hasTable('project_connection_incident_outbox_events'))) {
            $this->error('Run the Monitor module migration before enabling Monitor connection deliveries.');

            return self::FAILURE;
        }

        if (config('platform.products.analytics.enabled', false)
            && (! Schema::connection('analytics')->hasTable('site_release_annotations')
                || ! Schema::connection('analytics')->hasTable('site_incident_annotations'))) {
            $this->error('Run the Analytics module migration before enabling Analytics connection deliveries.');

            return self::FAILURE;
        }

        if ($retryFailed && ! $this->requeueFailedSourceEvent($source, $eventId)) {
            $this->error("The {$source} source event {$eventId} was not found in a failed state.");

            return self::FAILURE;
        }

        $this->recoverExpiredClaims($retryFailed ? $source : null, $retryFailed ? $eventId : null);
        $dispatched = 0;

        if (! $retryFailed || $source === 'deployer') {
            $dispatched += $this->dispatchDue($dispatch, $limit, $retryFailed ? $eventId : null);
        }

        if (config('platform.products.monitor.enabled', false) && (! $retryFailed || $source === 'monitor')) {
            $dispatched += $this->dispatchMonitorDue($monitorDispatch, $limit, $retryFailed ? $eventId : null);
        }

        $deliveryResults = $this->deliverDue(
            $deliveries,
            $limit,
            $retryFailed ? $source : null,
            $retryFailed ? $eventId : null,
        );

        $this->info(sprintf(
            'Dispatched %d source event(s); target deliveries: %d delivered, %d pending, %d blocked, %d failed, %d discarded.',
            $dispatched,
            $deliveryResults['delivered'],
            $deliveryResults['pending'],
            $deliveryResults['blocked'],
            $deliveryResults['failed'],
            $deliveryResults['discarded'],
        ));

        return self::SUCCESS;
    }

    private function requeueFailedSourceEvent(string $source, string $eventId): bool
    {
        $query = $source === 'deployer'
            ? DeploymentSucceededOutboxEvent::query()
            : ProjectConnectionIncidentOutboxEvent::query();

        return $query
            ->where('id', $eventId)
            ->where('status', 'failed')
            ->update([
                'status' => 'pending',
                'attempts' => 0,
                'available_at' => now(),
                'last_error_code' => null,
                'last_error_at' => null,
                'updated_at' => now(),
            ]) === 1;
    }

    private function dispatchDue(DispatchDeploymentSucceededOutboxEvent $dispatcher, int $limit, ?string $eventId = null): int
    {
        $dispatched = 0;
        $query = DeploymentSucceededOutboxEvent::query()
            ->where('status', 'pending')
            ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()));

        if ($eventId !== null) {
            $query->where('id', $eventId);
        }

        $eventIds = $query->orderBy('created_at')->limit($limit)->pluck('id');

        foreach ($eventIds as $eventId) {
            $event = DB::connection((new DeploymentSucceededOutboxEvent)->getConnectionName())
                ->transaction(function () use ($eventId): ?DeploymentSucceededOutboxEvent {
                    $query = DeploymentSucceededOutboxEvent::query()
                        ->where('status', 'pending')
                        ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()));

                    $event = $query->where('id', $eventId)->lockForUpdate()->first();

                    if ($event === null) {
                        return null;
                    }

                    $event->forceFill([
                        'status' => 'processing',
                        'attempts' => $event->attempts + 1,
                        'last_error_code' => null,
                    ])->save();

                    return $event->refresh();
                });

            if (! $event instanceof DeploymentSucceededOutboxEvent) {
                continue;
            }

            try {
                $dispatcher->dispatch($event);
                $updated = $this->finishClaimedSourceEvent($event, [
                    'status' => 'dispatched',
                    'dispatched_at' => now(),
                    'available_at' => null,
                    'last_error_code' => null,
                    'last_error_at' => null,
                ]);

                if ($updated) {
                    $dispatched++;
                }
            } catch (Throwable) {
                $terminal = $event->attempts >= 12;
                $backoffSeconds = min(86400, 60 * (2 ** min(10, max(0, $event->attempts - 1))));
                $this->finishClaimedSourceEvent($event, [
                    'status' => $terminal ? 'failed' : 'pending',
                    'available_at' => $terminal ? null : now()->addSeconds($backoffSeconds),
                    'last_error_code' => 'core_dispatch_failed',
                    'last_error_at' => now(),
                ]);
            }
        }

        return $dispatched;
    }

    private function dispatchMonitorDue(DispatchMonitorIncidentOutboxEvent $dispatcher, int $limit, ?string $eventId = null): int
    {
        $dispatched = 0;
        $query = ProjectConnectionIncidentOutboxEvent::query()
            ->where('status', 'pending')
            ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()));

        if ($eventId !== null) {
            $query->where('id', $eventId);
        }

        $eventIds = $query->orderBy('created_at')->limit($limit)->pluck('id');

        foreach ($eventIds as $eventId) {
            $event = DB::connection((new ProjectConnectionIncidentOutboxEvent)->getConnectionName())
                ->transaction(function () use ($eventId): ?ProjectConnectionIncidentOutboxEvent {
                    $query = ProjectConnectionIncidentOutboxEvent::query()
                        ->where('status', 'pending')
                        ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()));

                    $event = $query->where('id', $eventId)->lockForUpdate()->first();

                    if ($event === null) {
                        return null;
                    }

                    $event->forceFill([
                        'status' => 'processing',
                        'attempts' => $event->attempts + 1,
                        'last_error_code' => null,
                    ])->save();

                    return $event->refresh();
                });

            if (! $event instanceof ProjectConnectionIncidentOutboxEvent) {
                continue;
            }

            try {
                $dispatcher->dispatch($event);
                $updated = $this->finishClaimedSourceEvent($event, [
                    'status' => 'dispatched',
                    'dispatched_at' => now(),
                    'available_at' => null,
                    'last_error_code' => null,
                    'last_error_at' => null,
                ]);

                if ($updated) {
                    $dispatched++;
                }
            } catch (Throwable) {
                $terminal = $event->attempts >= 12;
                $backoffSeconds = min(86400, 60 * (2 ** min(10, max(0, $event->attempts - 1))));
                $this->finishClaimedSourceEvent($event, [
                    'status' => $terminal ? 'failed' : 'pending',
                    'available_at' => $terminal ? null : now()->addSeconds($backoffSeconds),
                    'last_error_code' => 'core_dispatch_failed',
                    'last_error_at' => now(),
                ]);
            }
        }

        return $dispatched;
    }

    /** @return array{delivered: int, pending: int, blocked: int, failed: int, discarded: int} */
    private function deliverDue(
        ProcessProjectConnectionDelivery $processor,
        int $limit,
        ?string $source = null,
        ?string $eventId = null,
    ): array {
        $results = ['delivered' => 0, 'pending' => 0, 'blocked' => 0, 'failed' => 0, 'discarded' => 0];
        $query = ProjectConnectionDelivery::query()
            ->where('status', 'pending')
            ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()));

        if ($source !== null && $eventId !== null) {
            $query->where('source_event_id', $eventId)->whereIn('event_type', $this->eventTypes($source));
        }

        $deliveryIds = $query->orderBy('created_at')->limit($limit)->pluck('id');

        foreach ($deliveryIds as $deliveryId) {
            $status = $processor->process((string) $deliveryId);
            if (array_key_exists($status, $results)) {
                $results[$status]++;
            }
        }

        return $results;
    }

    private function recoverExpiredClaims(?string $source = null, ?string $eventId = null): void
    {
        if ($source === null || $source === 'deployer') {
            $query = DeploymentSucceededOutboxEvent::query()
                ->where('status', 'processing')
                ->where('updated_at', '<', now()->subMinutes(10));

            if ($eventId !== null) {
                $query->where('id', $eventId);
            }

            $query->update([
                'status' => 'pending',
                'available_at' => now(),
                'last_error_code' => 'dispatch_lease_expired',
                'last_error_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (config('platform.products.monitor.enabled', false)
            && ($source === null || $source === 'monitor')
            && Schema::connection('monitor')->hasTable('project_connection_incident_outbox_events')) {
            $query = ProjectConnectionIncidentOutboxEvent::query()
                ->where('status', 'processing')
                ->where('updated_at', '<', now()->subMinutes(10));

            if ($eventId !== null) {
                $query->where('id', $eventId);
            }

            $query->update([
                'status' => 'pending',
                'available_at' => now(),
                'last_error_code' => 'dispatch_lease_expired',
                'last_error_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $query = ProjectConnectionDelivery::query()
            ->where('status', 'processing')
            ->where('last_attempted_at', '<', now()->subMinutes(10));

        if ($source !== null && $eventId !== null) {
            $query->where('source_event_id', $eventId)->whereIn('event_type', $this->eventTypes($source));
        }

        $query->update([
            'status' => 'pending',
            'available_at' => now(),
            'last_error_code' => 'delivery_lease_expired',
            'last_error_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return list<string> */
    private function eventTypes(string $source): array
    {
        return $source === 'deployer'
            ? [DeploymentSucceededOutboxEvent::EVENT_TYPE]
            : [
                ProjectConnectionIncidentOutboxEvent::OPENED,
                ProjectConnectionIncidentOutboxEvent::ACKNOWLEDGED,
                ProjectConnectionIncidentOutboxEvent::RESOLVED,
            ];
    }

    /**
     * Complete only the lease generation that performed the dispatch. A lease
     * can be recovered while a slow worker is still running, so an unguarded
     * model save here could overwrite a newer worker's result.
     *
     * @param  array<string, mixed>  $values
     */
    private function finishClaimedSourceEvent(
        DeploymentSucceededOutboxEvent|ProjectConnectionIncidentOutboxEvent $event,
        array $values,
    ): bool {
        return $event::query()
            ->whereKey($event->getKey())
            ->where('status', 'processing')
            ->where('attempts', $event->attempts)
            ->update([...$values, 'updated_at' => now()]) === 1;
    }
}
