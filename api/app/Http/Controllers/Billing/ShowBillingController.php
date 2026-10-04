<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Data\Billing\LimitUsage;
use App\Data\Billing\ServiceBillingCard;
use App\Data\Billing\TierOption;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\BillingAccount;
use App\Models\User;
use App\Platform\Catalog\Tier;
use App\Queries\Billing\BillingOverviewQuery;
use App\Queries\Billing\CostViewQuery;
use App\Queries\Billing\InvoicesQuery;
use App\Services\Billing\PlanUsage;
use App\Services\Billing\Referrals;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/account/billing`. */
final class ShowBillingController
{
    /**
     * Return everything the billing page shows: the plan of each service (with every tier's monthly and yearly
     * price), the total, limits and how close the account is to them, costs by project, referrals and invoices.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  BillingOverviewQuery  $overview
     * @param  InvoicesQuery  $invoices
     * @param  CostViewQuery  $costs
     * @param  Referrals  $referrals
     * @param  PlanUsage  $usage
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user, BillingOverviewQuery $overview, InvoicesQuery $invoices, CostViewQuery $costs, Referrals $referrals, PlanUsage $usage): JsonResponse
    {
        $summary = $overview->handle($account);
        $cost = $costs->handle($account, $summary);

        return response()->json([
            'account' => ['id' => $account->id, 'name' => $account->name],
            'canManage' => $user->can('manageBilling', $account),
            'interval' => (string) (BillingAccount::query()->whereKey($account->id)->value('interval') ?? 'month'),
            'monthlyTotalCents' => $summary->monthlyTotalCents,
            'currency' => $summary->currency,
            'status' => $summary->status,
            'periodEnd' => $summary->periodEnd?->toIso8601String(),
            'hasCustomer' => $summary->hasCustomer,
            'paymentsAvailable' => $summary->paymentsAvailable,
            'trialDays' => $summary->trialDays,
            'services' => array_map(fn (ServiceBillingCard $service): array => [
                'key' => $service->key,
                'name' => $service->name,
                'icon' => $service->icon,
                'tier' => $this->tier($service->tier),
                'endsAt' => $service->endsAt?->toIso8601String(),
                'inUse' => $service->inUse,
                'options' => array_map(fn (TierOption $option): array => ['tier' => $this->tier($option->tier), 'current' => $option->current, 'purchasable' => $option->purchasable], $service->options),
                'meters' => $service->meters,
            ], $summary->services),
            'limits' => array_map(function (LimitUsage $limit) use ($usage): array {
                $upgrade = $limit->nearLimit() && $limit->service !== 'account' ? $usage->upgradeFor($limit) : null;

                return [
                    'key' => $limit->key,
                    'service' => $limit->service,
                    'label' => $limit->label,
                    'used' => $limit->used,
                    'limit' => $limit->limit,
                    'monthly' => $limit->monthly,
                    'percent' => $limit->percent(),
                    'nearLimit' => $limit->nearLimit(),
                    'upgrade' => $upgrade === null ? null : ['tier' => $upgrade['tier']->name, 'limit' => $upgrade['limit'], 'monthlyCents' => $upgrade['monthlyCents']],
                ];
            }, $summary->limits),
            'costs' => [
                'currency' => $cost['currency'],
                'projects' => array_map(fn (array $row): array => ['name' => $row['project']->name, 'platform' => $row['platform'], 'cloud' => $row['cloud']], $cost['projects']),
                'unassigned' => $cost['unassigned'],
                'unpriced' => $cost['unpriced'],
                'platformTotal' => $cost['platform_total'],
                'cloudTotal' => $cost['cloud_total'],
                'billed' => $cost['billed'],
            ],
            'referrals' => $referrals->summary($account),
            'invoices' => $invoices->handle($account),
        ]);
    }

    /**
     * Describe a tier with both its monthly and yearly prices.
     *
     * @param  Tier  $tier
     * @return array<string, mixed>
     */
    private function tier(Tier $tier): array
    {
        return [
            'key' => $tier->key,
            'name' => $tier->name,
            'description' => $tier->description,
            'features' => $tier->features,
            'monthlyCents' => $tier->monthlyCents,
            'yearlyCents' => $tier->yearlyCents(),
        ];
    }
}
