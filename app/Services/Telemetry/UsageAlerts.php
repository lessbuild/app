<?php

declare(strict_types=1);

namespace App\Services\Telemetry;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\TelemetryUsageEntry;
use App\Models\UsageAlertDelivery;
use App\Models\User;
use App\Notifications\UsageAlertNotification;
use App\Services\Monitoring\EmailDeliveryLedger;
use Carbon\CarbonImmutable;

/**
 * Emails account owners when this month's telemetry reaches 80% and 100% of the Monitoring allowance, once each per month
 * (and only the higher one when both are reached between runs).
 *
 * @phpstan-type Usage array{period_start: CarbonImmutable, period_end: CarbonImmutable, event_count: int, event_limit: int|null, percentage: int, crossed: list<int>}
 */
final class UsageAlerts
{
    public const THRESHOLDS = [80, 100];

    /**
     * Sends usage alerts.
     *
     * @param  TelemetryUsage  $usage  Counts the month's events and reads the allowance.
     * @param  EmailDeliveryLedger  $ledger  Makes sure each alert is sent once and records how it went.
     */
    public function __construct(private readonly TelemetryUsage $usage, private readonly EmailDeliveryLedger $ledger) {}

    /**
     * The account's usage this month: events counted, the allowance, the percentage used and the thresholds crossed.
     *
     * @return Usage
     */
    public function summary(Account $account, ?CarbonImmutable $at = null): array
    {
        $at = ($at ?? CarbonImmutable::now('UTC'))->utc();
        $count = $this->usage->eventsThisMonth($account, $at);
        $limit = $this->usage->eventLimit($account);
        $crossed = $limit === null || $limit === 0 ? [] : array_values(array_filter(self::THRESHOLDS, fn (int $threshold): bool => $count >= (int) ceil($limit * $threshold / 100)));

        return [
            'period_start' => $at->startOfMonth(),
            'period_end' => $at->startOfMonth()->addMonth(),
            'event_count' => $count,
            'event_limit' => $limit,
            'percentage' => $limit === null || $limit === 0 ? 0 : (int) min(100, round($count / $limit * 100)),
            'crossed' => $crossed,
        ];
    }

    /**
     * Emails each verified owner of accounts that sent telemetry this month about the highest threshold crossed, once
     * per threshold per month, and returns how many were sent, skipped or failed.
     *
     * @return array{sent: int, skipped: int, failed: int}
     */
    public function send(?CarbonImmutable $at = null, ?string $accountId = null): array
    {
        $at = ($at ?? CarbonImmutable::now('UTC'))->utc();
        $totals = ['sent' => 0, 'skipped' => 0, 'failed' => 0];
        $accounts = Account::query()->when($accountId !== null, fn ($query) => $query->whereKey($accountId))
            ->whereIn('id', TelemetryUsageEntry::query()->where('received_at', '>=', $at->startOfMonth()->format('Y-m-d H:i:s.u'))->select('account_id'))
            ->with(['memberships.user']);
        foreach ($accounts->lazyById(100) as $account) {
            $summary = $this->summary($account, $at);
            // Only the highest threshold reached: jumping straight past 80% sends just the 100% email.
            foreach (array_slice($summary['crossed'], -1) as $threshold) {
                foreach ($this->owners($account) as $owner) {
                    $totals[$this->ledger->send(UsageAlertDelivery::class, [
                        'account_id' => $account->id, 'recipient_id' => $owner->id,
                        'period_start' => $summary['period_start']->format('Y-m-d H:i:s.u'), 'threshold' => $threshold,
                    ], ['event_count' => $summary['event_count'], 'event_limit' => (int) $summary['event_limit']],
                        fn () => $owner->notifyNow(new UsageAlertNotification($account, $summary, $threshold)))]++;
                }
            }
        }

        return $totals;
    }

    /**
     * The account's owners with a verified email.
     *
     * @return list<User>
     */
    private function owners(Account $account): array
    {
        $owners = [];
        foreach ($account->memberships as $membership) {
            if ($membership->role === AccountRole::Owner && $membership->user->email_verified_at !== null) {
                $owners[] = $membership->user;
            }
        }

        return $owners;
    }
}
