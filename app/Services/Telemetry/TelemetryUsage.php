<?php

declare(strict_types=1);

namespace App\Services\Telemetry;

use App\Models\Account;
use App\Models\TelemetryUsageEntry;
use App\Services\Billing\Entitlements;
use Carbon\CarbonImmutable;

/**
 * Monthly telemetry events against the account's Monitoring allowance (`monitoring.events.monthly`), counted from the
 * per-delivery ledger (telemetry_usage_entries) in the month each delivery arrived.
 */
final class TelemetryUsage
{
    public const METER = 'monitoring.events';

    public function __construct(private readonly Entitlements $entitlements) {}

    public function eventsThisMonth(Account $account, ?CarbonImmutable $at = null): int
    {
        $at = ($at ?? CarbonImmutable::now('UTC'))->utc();

        return (int) TelemetryUsageEntry::query()->whereBelongsTo($account)
            ->where('received_at', '>=', $at->startOfMonth()->format('Y-m-d H:i:s.u'))
            ->where('received_at', '<=', $at->format('Y-m-d H:i:s.u'))
            ->sum('event_count');
    }

    /** The monthly allowance; null means unlimited. */
    public function eventLimit(Account $account): ?int
    {
        return $this->entitlements->for($account)->limit('monitoring.events.monthly');
    }

    /** Whether a batch received at `$receivedAt` still fits in that month's allowance. */
    public function canAccept(Account $account, int $events, CarbonImmutable $receivedAt): bool
    {
        $limit = $this->eventLimit($account);

        return $limit === null || $this->eventsThisMonth($account, $receivedAt) + $events <= $limit;
    }
}
