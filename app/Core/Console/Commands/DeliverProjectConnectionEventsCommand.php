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
    protected $signature = 'project-connections:deliver {--limit=100 : Maximum source and target events to process (1-1000)} {--retry-failed : Requeue source events that exhausted automatic delivery attempts}';

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

        if ($this->option('retry-failed')) {
            DeploymentSucceededOutboxEvent::query()
                ->where('status', 'failed')
                ->update([
                    'status' => 'pending',
                    'attempts' => 0,
                    'available_at' => now(),
                    'last_error_code' => null,
                    'last_error_at' => null,
                    'updated_at' => now(),
                ]);

            if (config('platform.products.monitor.enabled', false)) {
                ProjectConnectionIncidentOutboxEvent::query()
                    ->where('status', 'failed')
                    ->update([
                        'status' => 'pending',
                        'attempts' => 0,
                        'available_at' => now(),
                        'last_error_code' => null,
                        'last_error_at' => null,
                        'updated_at' => now(),
                    ]);
            }
        }

        $this->recoverExpiredClaims();
        $dispatched = $this->dispatchDue($dispatch, $limit);
        if (config('platform.products.monitor.enabled', false)) {
            $dispatched += $this->dispatchMonitorDue($monitorDispatch, $limit);
        }
        $deliveryResults = $this->deliverDue($deliveries, $limit);

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

    private function dispatchDue(DispatchDeploymentSucceededOutboxEvent $dispatcher, int $limit): int
    {
        $dispatched = 0;
        $eventIds = DeploymentSucceededOutboxEvent::query()
            ->where('status', 'pending')
            ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->orderBy('created_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($eventIds as $eventId) {
            $event = DB::connection((new DeploymentSucceededOutboxEvent)->getConnectionName())
                ->transaction(function () use ($eventId): ?DeploymentSucceededOutboxEvent {
                    $event = DeploymentSucceededOutboxEvent::query()
                        ->whereKey($eventId)
                        ->where('status', 'pending')
                        ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
                        ->lockForUpdate()
                        ->first();

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

    private function dispatchMonitorDue(DispatchMonitorIncidentOutboxEvent $dispatcher, int $limit): int
    {
        $dispatched = 0;
        $eventIds = ProjectConnectionIncidentOutboxEvent::query()
            ->where('status', 'pending')
            ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->orderBy('created_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($eventIds as $eventId) {
            $event = DB::connection((new ProjectConnectionIncidentOutboxEvent)->getConnectionName())
                ->transaction(function () use ($eventId): ?ProjectConnectionIncidentOutboxEvent {
                    $event = ProjectConnectionIncidentOutboxEvent::query()
                        ->whereKey($eventId)
                        ->where('status', 'pending')
                        ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
                        ->lockForUpdate()
                        ->first();

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
    private function deliverDue(ProcessProjectConnectionDelivery $processor, int $limit): array
    {
        $results = ['delivered' => 0, 'pending' => 0, 'blocked' => 0, 'failed' => 0, 'discarded' => 0];
        $deliveryIds = ProjectConnectionDelivery::query()
            ->where('status', 'pending')
            ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->orderBy('created_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($deliveryIds as $deliveryId) {
            $status = $processor->process((string) $deliveryId);
            if (array_key_exists($status, $results)) {
                $results[$status]++;
            }
        }

        return $results;
    }

    private function recoverExpiredClaims(): void
    {
        DeploymentSucceededOutboxEvent::query()
            ->where('status', 'processing')
            ->where('updated_at', '<', now()->subMinutes(10))
            ->update([
                'status' => 'pending',
                'available_at' => now(),
                'last_error_code' => 'dispatch_lease_expired',
                'last_error_at' => now(),
                'updated_at' => now(),
            ]);

        if (config('platform.products.monitor.enabled', false)
            && Schema::connection('monitor')->hasTable('project_connection_incident_outbox_events')) {
            ProjectConnectionIncidentOutboxEvent::query()
                ->where('status', 'processing')
                ->where('updated_at', '<', now()->subMinutes(10))
                ->update([
                    'status' => 'pending',
                    'available_at' => now(),
                    'last_error_code' => 'dispatch_lease_expired',
                    'last_error_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        ProjectConnectionDelivery::query()
            ->where('status', 'processing')
            ->where('last_attempted_at', '<', now()->subMinutes(10))
            ->update([
                'status' => 'pending',
                'available_at' => now(),
                'last_error_code' => 'delivery_lease_expired',
                'last_error_at' => now(),
                'updated_at' => now(),
            ]);
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
