<?php

namespace App\Core\Console\Commands;

use App\Core\Contracts\ProjectConnectionOutboxSource;
use App\Core\Data\Connections\ProjectConnectionOutboxEvent;
use App\Core\Services\Connections\DispatchDeploymentSucceededOutboxEvent;
use App\Core\Services\Connections\DispatchMonitorIncidentOutboxEvent;
use App\Core\Services\Connections\ProjectConnectionOutboxSourceRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ReconcileProjectConnectionDeliveriesCommand extends Command
{
    protected $signature = 'project-connections:reconcile
        {--source= : Source product: deployer or monitor}
        {--event-id= : Reconcile one source outbox event ULID; requires --source}
        {--limit=100 : Maximum source events to inspect (1-1000)}
        {--apply : Create missing eligible Core delivery records; default is read-only}';

    protected $description = 'Preview or repair missing project connection delivery records';

    public function handle(
        ProjectConnectionOutboxSourceRegistry $sources,
        DispatchDeploymentSucceededOutboxEvent $deployerDispatcher,
        DispatchMonitorIncidentOutboxEvent $monitorDispatcher,
    ): int {
        $source = $this->option('source');
        $source = is_string($source) && trim($source) !== '' ? strtolower(trim($source)) : null;
        $eventId = $this->option('event-id');
        $eventId = is_string($eventId) && trim($eventId) !== '' ? strtolower(trim($eventId)) : null;
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);

        if ($limit === false) {
            $this->error('The limit must be an integer between 1 and 1000.');

            return self::INVALID;
        }

        if ($source !== null && ! in_array($source, ['deployer', 'monitor'], true)) {
            $this->error('The source must be deployer or monitor.');

            return self::INVALID;
        }

        if ($eventId !== null && ($source === null || ! preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $eventId))) {
            $this->error('A valid --source is required with a 26-character --event-id ULID.');

            return self::INVALID;
        }

        if (! Schema::connection('core')->hasTable('project_connection_deliveries')
            || ! Schema::connection('core')->hasTable('project_connections')
            || ! Schema::connection('core')->hasTable('project_resources')) {
            $this->error('Run the Core module migrations before reconciling project connection deliveries.');

            return self::FAILURE;
        }

        $products = $source === null
            ? ['deployer', ...(config('platform.products.monitor.enabled', false) ? ['monitor'] : [])]
            : [$source];
        $activeSources = [];

        foreach ($products as $product) {
            if ($product === 'monitor' && ! config('platform.products.monitor.enabled', false)) {
                $this->error('Enable the Monitor module before reconciling Monitor incident events.');

                return self::FAILURE;
            }

            $outbox = $sources->get($product);

            if (! $outbox instanceof ProjectConnectionOutboxSource || ! $outbox->hasRequiredTables()) {
                $this->error("Run the {$product} module migrations before reconciling its source events.");

                return self::FAILURE;
            }

            $activeSources[$product] = $outbox;
        }

        $events = $this->sourceEvents($activeSources, $eventId, $limit);

        if ($eventId !== null && $events === []) {
            $this->error("The {$source} source event {$eventId} was not found in a dispatched or failed state.");

            return self::FAILURE;
        }

        $rows = [];
        $totalMissing = 0;
        $totalCreated = 0;
        $hasErrors = false;

        foreach ($events as [$product, $event]) {
            try {
                $dispatcher = $product === 'deployer' ? $deployerDispatcher : $monitorDispatcher;
                $missing = $dispatcher->missingDeliveryCount($event);
                $created = $this->option('apply') ? $dispatcher->dispatch($event) : 0;
            } catch (Throwable $exception) {
                $hasErrors = true;
                $rows[] = [$product, $event->id, $event->status, 'error: '.$exception::class];

                continue;
            }

            $totalMissing += $missing;
            $totalCreated += $created;
            $rows[] = [
                $product,
                $event->id,
                $event->status,
                $this->option('apply') ? "{$created} created (preview: {$missing})" : "{$missing} missing",
            ];
        }

        if ($rows !== []) {
            $this->table(['Source', 'Event ULID', 'Source status', $this->option('apply') ? 'Applied' : 'Would create'], $rows);
        }

        if ($this->option('apply')) {
            $this->info("Created {$totalCreated} missing Core delivery record(s). New records remain pending for the normal delivery worker.");
        } else {
            $this->info("Read-only preview: {$totalMissing} missing Core delivery record(s) across ".count($events).' source event(s). Pass --apply to create only these eligible records.');
        }

        return $hasErrors ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, ProjectConnectionOutboxSource>  $sources
     * @return list<array{0: string, 1: ProjectConnectionOutboxEvent}>
     */
    private function sourceEvents(array $sources, ?string $eventId, int $limit): array
    {
        $events = [];

        foreach ($sources as $product => $source) {
            foreach ($source->reconciliationEvents($eventId, $limit) as $event) {
                $events[] = [$product, $event];
            }
        }

        usort($events, static fn (array $left, array $right): int => $left[1]->createdAt <=> $right[1]->createdAt);

        return array_slice($events, 0, $limit);
    }
}
