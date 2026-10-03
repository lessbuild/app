<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Notifications\InfrastructureBudgetReached;
use App\Queries\Infrastructure\InfrastructureCostsQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Tells account owners when their servers' monthly cost reaches 80% and then 100% of the infrastructure budget, once
 * per threshold each month. The budget is in USD, so servers priced in other currencies aren't counted.
 */
final class InfrastructureBudgetAlerts
{
    /** The shares of the budget that trigger an alert, in percent. */
    public const THRESHOLDS = [80, 100];

    /**
     * Create a new InfrastructureBudgetAlerts instance.
     *
     * @param  InfrastructureCostsQuery  $costs  Totals the servers' monthly costs.
     */
    public function __construct(private readonly InfrastructureCostsQuery $costs) {}

    /**
     * Check every account with a budget and alert its owners about thresholds newly crossed this month. Returns how
     * many alerts were sent.
     *
     * @return int
     */
    public function check(): int
    {
        $sent = 0;
        $period = now('UTC')->format('Y-m');
        Account::query()->whereNotNull('monthly_infrastructure_budget')->where('monthly_infrastructure_budget', '>', 0)->with('memberships.user')
            ->lazyById(100)->each(function (Account $account) use ($period, &$sent): void {
                $budget = (float) $account->monthly_infrastructure_budget;
                $cost = (float) ($this->costs->handle($account->id)['totals']['USD'] ?? 0);
                $crossed = array_values(array_filter(self::THRESHOLDS, fn (int $threshold): bool => $cost >= $budget * $threshold / 100));
                if ($crossed === []) {
                    return;
                }
                $threshold = max($crossed);
                // Record every crossed threshold; alert only when the highest is new, so there's no 80% alert after 100%.
                $highestIsNew = false;
                foreach ($crossed as $each) {
                    $inserted = DB::table('infrastructure_budget_alerts')->insertOrIgnore(['account_id' => $account->id, 'period' => $period, 'threshold' => $each, 'monthly_cost' => $cost, 'created_at' => now()]);
                    $highestIsNew = $highestIsNew || ($each === $threshold && $inserted === 1);
                }
                if (! $highestIsNew) {
                    return;
                }
                $project = $account->projects()->whereHas('enabledServices', fn ($query) => $query->where('service', 'infrastructure'))->orderBy('created_at')->first() ?? $account->projects()->orderBy('created_at')->first();
                $owners = $account->memberships->filter(fn ($membership): bool => $membership->role === AccountRole::Owner && $membership->user->email_verified_at !== null)->map->user;
                Notification::send($owners, new InfrastructureBudgetReached($account->id, $account->name, $threshold, $cost, $budget,
                    $project !== null ? route('infrastructure.costs', $project) : route('dashboard')));
                $sent++;
            });

        return $sent;
    }
}
