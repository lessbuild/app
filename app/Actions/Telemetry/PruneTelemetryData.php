<?php

declare(strict_types=1);

namespace App\Actions\Telemetry;

use App\Enums\IngestStatus;
use App\Models\Account;
use App\Models\Environment;
use App\Models\IngestPayload;
use App\Models\IngestReceipt;
use App\Models\Project;
use App\Models\TelemetryEvent;
use App\Models\TelemetryEventIdentity;
use App\Services\Billing\Entitlements;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PruneTelemetryData
{
    /**
     * Deletes telemetry older than each account's plan keeps.
     *
     * @param  Entitlements  $entitlements  Reads each account's retention.
     */
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Delete telemetry events and finished receipts older than each account's Monitoring retention.
     *
     * @return array{accounts: int, events: int, identities: int, receipts: int, payloads: int, dry_run: bool}
     */
    public function handle(bool $dryRun = false, ?CarbonImmutable $now = null, ?string $accountId = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $summary = [
            'accounts' => 0,
            'events' => 0,
            'identities' => 0,
            'receipts' => 0,
            'payloads' => 0,
            'dry_run' => $dryRun,
        ];

        Account::query()
            ->when($accountId !== null, fn (Builder $query): Builder => $query->whereKey($accountId))
            ->orderBy('id')
            ->eachById(function (Account $account) use (&$summary, $dryRun, $now): void {
                $cutoff = $now->subDays($this->retentionDays($account));
                $events = $this->pruneEvents($account, $cutoff, $dryRun);
                $receipts = $this->pruneReceipts($account, $cutoff, $dryRun);

                $summary['accounts']++;
                $summary['events'] += $events['events'];
                $summary['identities'] += $events['identities'];
                $summary['receipts'] += $receipts['receipts'];
                $summary['payloads'] += $receipts['payloads'];
            });

        return $summary;
    }

    /**
     * Deletes the account's events older than the cutoff, with their deduplication identities, 500 at a time in
     * transactions. A dry run only counts them.
     *
     * @return array{events: int, identities: int}
     */
    private function pruneEvents(Account $account, CarbonImmutable $cutoff, bool $dryRun): array
    {
        $events = $this->eventsFor($account, $cutoff);
        $identities = TelemetryEventIdentity::query()
            ->whereIn('telemetry_event_id', (clone $events)->select('id'));

        if ($dryRun) {
            return ['events' => $events->count(), 'identities' => $identities->count()];
        }

        $deletedEvents = 0;
        $deletedIdentities = 0;
        $events->select('id')->orderBy('id')->chunkById(500, function (Collection $chunk) use (&$deletedEvents, &$deletedIdentities): void {
            $ids = $chunk->pluck('id')->all();

            DB::transaction(function () use ($ids, &$deletedEvents, &$deletedIdentities): void {
                $deletedIdentities += TelemetryEventIdentity::query()->whereIn('telemetry_event_id', $ids)->delete();
                $deletedEvents += TelemetryEvent::query()->whereKey($ids)->delete();
            });
        });

        return ['events' => $deletedEvents, 'identities' => $deletedIdentities];
    }

    /**
     * Deletes completed ingest receipts older than the cutoff, with any payload still kept, the same way.
     *
     * @return array{receipts: int, payloads: int}
     */
    private function pruneReceipts(Account $account, CarbonImmutable $cutoff, bool $dryRun): array
    {
        $receipts = $this->completedReceiptsFor($account, $cutoff);
        $payloads = IngestPayload::query()->whereIn('ingest_receipt_id', (clone $receipts)->select('id'));

        if ($dryRun) {
            return ['receipts' => $receipts->count(), 'payloads' => $payloads->count()];
        }

        $deletedReceipts = 0;
        $deletedPayloads = 0;
        $receipts->select('id')->orderBy('id')->chunkById(500, function (Collection $chunk) use (&$deletedReceipts, &$deletedPayloads): void {
            $ids = $chunk->pluck('id')->all();

            DB::transaction(function () use ($ids, &$deletedReceipts, &$deletedPayloads): void {
                $deletedPayloads += IngestPayload::query()->whereIn('ingest_receipt_id', $ids)->delete();
                $deletedReceipts += IngestReceipt::query()->whereKey($ids)->where('status', IngestStatus::Completed)->delete();
            });
        });

        return ['receipts' => $deletedReceipts, 'payloads' => $deletedPayloads];
    }

    /**
     * The account's events from before the cutoff.
     *
     * @return Builder<TelemetryEvent>
     */
    private function eventsFor(Account $account, CarbonImmutable $cutoff): Builder
    {
        $projectIds = Project::query()->whereBelongsTo($account)->select('id');
        $environmentIds = Environment::query()->whereIn('project_id', $projectIds)->select('id');

        return TelemetryEvent::query()
            ->whereIn('environment_id', $environmentIds)
            ->where('occurred_at', '<', $cutoff);
    }

    /**
     * The account's completed receipts last touched before the cutoff. Receipts still processing or failed are kept.
     *
     * @return Builder<IngestReceipt>
     */
    private function completedReceiptsFor(Account $account, CarbonImmutable $cutoff): Builder
    {
        return IngestReceipt::query()
            ->whereBelongsTo($account)
            ->where('status', IngestStatus::Completed)
            ->where('last_received_at', '<', $cutoff);
    }

    /**
     * How many days the account's plan keeps telemetry, at least one; ten years when the plan sets no limit.
     */
    private function retentionDays(Account $account): int
    {
        return max(1, $this->entitlements->for($account)->limit('monitoring.retention.days') ?? 3650);
    }
}
