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

    /**
     * Create a new TelemetryUsage instance.
     *
     * Counts telemetry against allowances.
     *
     * @param  Entitlements  $entitlements  Reads the account's allowance.
     */
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Count the events recorded for the account from the start of the month up to the moment given.
     *
     * @param  Account  $account
     * @param  CarbonImmutable|null  $at
     * @return int
     */
    public function eventsThisMonth(Account $account, ?CarbonImmutable $at = null): int
    {
        $at = ($at ?? CarbonImmutable::now('UTC'))->utc();

        return (int) TelemetryUsageEntry::query()->whereBelongsTo($account)
            ->where('received_at', '>=', $at->startOfMonth()->format('Y-m-d H:i:s.u'))
            ->where('received_at', '<=', $at->format('Y-m-d H:i:s.u'))
            ->sum('event_count');
    }

    /**
     * Get the account's monthly allowance; null means unlimited.
     *
     * @param  Account  $account
     * @return int|null
     */
    public function eventLimit(Account $account): ?int
    {
        return $this->entitlements->for($account)->limit('monitoring.events.monthly');
    }

    /**
     * Determine whether a batch received at `$receivedAt` still fits in that month's allowance.
     *
     * @param  Account  $account
     * @param  int  $events
     * @param  CarbonImmutable  $receivedAt
     * @return bool
     */
    public function canAccept(Account $account, int $events, CarbonImmutable $receivedAt): bool
    {
        $limit = $this->eventLimit($account);

        return $limit === null || $this->eventsThisMonth($account, $receivedAt) + $events <= $limit;
    }
}
