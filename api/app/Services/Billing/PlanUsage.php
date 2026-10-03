<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Data\Billing\LimitUsage;
use App\Models\Account;
use App\Models\AnalyticsSite;
use App\Models\Dashboard;
use App\Models\Preview;
use App\Models\Project;
use App\Models\Server;
use App\Models\UsageRecord;
use App\Models\Website;
use App\Platform\Catalog\Tier;
use App\Platform\ServiceRegistry;

/**
 * Counts what an account uses against each plan limit, the same way the limits are checked: servers, websites and
 * open previews, dashboards, Analytics sites, members (with pending invitations), and this month's metered usage.
 */
final class PlanUsage
{
    /**
     * Create a new PlanUsage instance.
     *
     * @param  Entitlements  $entitlements  Knows each limit on the account's plans.
     * @param  ServiceRegistry  $services  Lists each service's tiers, for upgrade suggestions.
     * @param  PriceBook  $prices  Says which tiers are on sale.
     */
    public function __construct(private readonly Entitlements $entitlements, private readonly ServiceRegistry $services, private readonly PriceBook $prices) {}

    /**
     * Get the account's usage of every limit its plans set, the counted ones first.
     *
     * @param  Account  $account
     * @return list<LimitUsage>
     */
    public function handle(Account $account): array
    {
        $plan = $this->entitlements->for($account);
        $projects = Project::query()->where('account_id', $account->id)->select('id');
        $monthly = UsageRecord::query()->where('account_id', $account->id)->where('period_start', '>=', now()->utc()->startOfMonth())
            ->selectRaw('meter, sum(quantity) as total')->groupBy('meter')->pluck('total', 'meter');
        $rows = [
            ['infrastructure.servers.max', 'infrastructure', __('servers'), fn (): int => Server::query()->where('account_id', $account->id)->count(), false],
            ['deploy.websites.max', 'deploy', __('websites'), fn (): int => Website::query()->where('account_id', $account->id)->count(), false],
            ['deploy.previews.max', 'deploy', __('open previews'), fn (): int => Preview::query()->whereIn('project_id', $projects)->where('status', '!=', Preview::STATUS_CLOSED)->count(), false],
            ['monitoring.dashboards.max', 'monitoring', __('dashboards'), fn (): int => Dashboard::query()->where('account_id', $account->id)->count(), false],
            ['analytics.sites.max', 'analytics', __('Analytics sites'), fn (): int => AnalyticsSite::query()->whereIn('project_id', $projects)->count(), false],
            ['account.members.max', 'account', __('members'), fn (): int => $account->memberships()->count() + $account->invitations()->pending()->count(), false],
            ['monitoring.events.monthly', 'monitoring', __('Monitoring events'), fn (): int => (int) ($monthly['monitoring.events'] ?? 0), true],
            ['analytics.pageviews.monthly', 'analytics', __('pageviews'), fn (): int => (int) ($monthly['analytics.pageviews'] ?? 0), true],
        ];
        $usage = [];
        foreach ($rows as [$key, $service, $label, $count, $isMonthly]) {
            $limit = $plan->limit($key);
            if ($limit !== null) {
                $usage[] = new LimitUsage($key, $service, $label, $count(), $limit, $isMonthly);
            }
        }

        return $usage;
    }

    /**
     * Get the monthly allowance the account is closest to running out of, when it's used 80% or more of one. Count
     * limits (servers, websites…) aren't included: a free plan's single server is "full" by design, so those are
     * raised when someone tries to go past them instead of on every page.
     *
     * @param  Account  $account
     * @return LimitUsage|null
     */
    public function nearest(Account $account): ?LimitUsage
    {
        $near = array_filter($this->handle($account), fn (LimitUsage $usage): bool => $usage->monthly && $usage->nearLimit());
        usort($near, fn (LimitUsage $a, LimitUsage $b): int => $b->percent() <=> $a->percent());

        return $near[0] ?? null;
    }

    /**
     * Suggest the cheapest plan on sale that raises a limit: the tier, its new limit (null for unlimited), and its
     * monthly price. Null when no plan raises it (or it's an account-wide limit).
     *
     * @param  LimitUsage  $usage
     * @return array{service: string, tier: Tier, limit: int|null, monthlyCents: int}|null
     */
    public function upgradeFor(LimitUsage $usage): ?array
    {
        $service = $this->services->find($usage->service);
        if ($service === null) {
            return null;
        }
        $candidates = array_filter($service->billing()->tiers, fn (Tier $tier): bool => ! $tier->isFree() && $tier->monthlyCents !== null
            && $this->prices->purchasable($usage->service, $tier)
            && array_key_exists($usage->key, $tier->limits)
            && ($tier->limits[$usage->key] === null || $tier->limits[$usage->key] > (int) $usage->limit));
        usort($candidates, fn (Tier $a, Tier $b): int => $a->monthlyCents <=> $b->monthlyCents);
        $tier = $candidates[0] ?? null;

        return $tier === null ? null : ['service' => $service->name(), 'tier' => $tier, 'limit' => $tier->limits[$usage->key], 'monthlyCents' => (int) $tier->monthlyCents];
    }
}
