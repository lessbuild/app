<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Data\Telemetry\IngestStatus;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\IngestPayload;
use App\Modules\Monitor\Models\IngestReceipt;
use App\Modules\Monitor\Models\TelemetryEvent;
use App\Modules\Monitor\Models\TelemetryEventIdentity;
use App\Modules\Monitor\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PruneTelemetryData
{
    /**
     * @return array{workspaces: int, events: int, identities: int, receipts: int, payloads: int, dry_run: bool}
     */
    public function prune(bool $dryRun = false, ?CarbonImmutable $now = null, ?int $workspaceId = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $summary = [
            'workspaces' => 0,
            'events' => 0,
            'identities' => 0,
            'receipts' => 0,
            'payloads' => 0,
            'dry_run' => $dryRun,
        ];

        Workspace::query()
            ->when($workspaceId !== null, fn (Builder $query): Builder => $query->whereKey($workspaceId))
            ->orderBy('id')
            ->eachById(function (Workspace $workspace) use (&$summary, $dryRun, $now): void {
                $cutoff = $now->subDays($this->retentionDays($workspace));
                $events = $this->pruneEvents($workspace, $cutoff, $dryRun);
                $receipts = $this->pruneReceipts($workspace, $cutoff, $dryRun);

                $summary['workspaces']++;
                $summary['events'] += $events['events'];
                $summary['identities'] += $events['identities'];
                $summary['receipts'] += $receipts['receipts'];
                $summary['payloads'] += $receipts['payloads'];
            });

        return $summary;
    }

    /**
     * @return array{events: int, identities: int}
     */
    private function pruneEvents(Workspace $workspace, CarbonImmutable $cutoff, bool $dryRun): array
    {
        $events = $this->eventsFor($workspace, $cutoff);
        $identities = TelemetryEventIdentity::query()
            ->whereIn('telemetry_event_id', (clone $events)->select('id'));

        if ($dryRun) {
            return ['events' => $events->count(), 'identities' => $identities->count()];
        }

        $deletedEvents = 0;
        $deletedIdentities = 0;
        $events->select('id')->orderBy('id')->chunkById(500, function (Collection $chunk) use (&$deletedEvents, &$deletedIdentities): void {
            $ids = $chunk->pluck('id')->all();

            DB::connection('monitor')->transaction(function () use ($ids, &$deletedEvents, &$deletedIdentities): void {
                $deletedIdentities += TelemetryEventIdentity::query()->whereIn('telemetry_event_id', $ids)->delete();
                $deletedEvents += TelemetryEvent::query()->whereKey($ids)->delete();
            });
        });

        return ['events' => $deletedEvents, 'identities' => $deletedIdentities];
    }

    /**
     * @return array{receipts: int, payloads: int}
     */
    private function pruneReceipts(Workspace $workspace, CarbonImmutable $cutoff, bool $dryRun): array
    {
        $receipts = $this->completedReceiptsFor($workspace, $cutoff);
        $payloads = IngestPayload::query()->whereIn('ingest_receipt_id', (clone $receipts)->select('id'));

        if ($dryRun) {
            return ['receipts' => $receipts->count(), 'payloads' => $payloads->count()];
        }

        $deletedReceipts = 0;
        $deletedPayloads = 0;
        $receipts->select('id')->orderBy('id')->chunkById(500, function (Collection $chunk) use (&$deletedReceipts, &$deletedPayloads): void {
            $ids = $chunk->pluck('id')->all();

            DB::connection('monitor')->transaction(function () use ($ids, &$deletedReceipts, &$deletedPayloads): void {
                $deletedPayloads += IngestPayload::query()->whereIn('ingest_receipt_id', $ids)->delete();
                $deletedReceipts += IngestReceipt::query()->whereKey($ids)->where('status', IngestStatus::Completed)->delete();
            });
        });

        return ['receipts' => $deletedReceipts, 'payloads' => $deletedPayloads];
    }

    /**
     * @return Builder<TelemetryEvent>
     */
    private function eventsFor(Workspace $workspace, CarbonImmutable $cutoff): Builder
    {
        $applicationIds = Application::withTrashed()->whereBelongsTo($workspace)->select('id');
        $environmentIds = Environment::withTrashed()->whereIn('application_id', $applicationIds)->select('id');

        return TelemetryEvent::query()
            ->whereIn('environment_id', $environmentIds)
            ->where('occurred_at', '<', $cutoff);
    }

    /**
     * @return Builder<IngestReceipt>
     */
    private function completedReceiptsFor(Workspace $workspace, CarbonImmutable $cutoff): Builder
    {
        return IngestReceipt::query()
            ->whereBelongsTo($workspace)
            ->where('status', IngestStatus::Completed)
            ->where('last_received_at', '<', $cutoff);
    }

    private function retentionDays(Workspace $workspace): int
    {
        $value = config('monitor.beacon.plans.'.$workspace->plan.'.retention_days', config('monitor.beacon.plans.free.retention_days', 7));

        return is_numeric($value) ? max(1, (int) $value) : 7;
    }
}
