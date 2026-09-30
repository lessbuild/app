<?php

declare(strict_types=1);

namespace App\Queries\Infrastructure;

use App\Models\Provider;
use App\Models\ProviderBill;
use App\Models\Server;
use App\Services\Infrastructure\CloudBills;

final class CloudBillsQuery
{
    /**
     * Compare each cloud provider's actual charges with the estimate from its servers' list prices: this month so far,
     * last month's invoice, and why the bills couldn't be read when they couldn't.
     *
     * @param  string  $accountId
     * @return list<array{provider: Provider, estimate: float|null, current: ProviderBill|null, previous: ProviderBill|null, difference: float|null}>
     */
    public function handle(string $accountId): array
    {
        $providers = Provider::query()->where('account_id', $accountId)
            ->whereIn('type', array_map(fn ($type): string => $type->value, CloudBills::SUPPORTED))->orderBy('name')->get();
        if ($providers->isEmpty()) {
            return [];
        }
        $bills = ProviderBill::query()->whereIn('provider_id', $providers->modelKeys())
            ->whereIn('period', [now()->format('Y-m'), now()->subMonthNoOverflow()->format('Y-m')])->get()
            ->groupBy('provider_id');
        $estimates = Server::query()->whereIn('provider_id', $providers->modelKeys())->whereNotNull('monthly_cost')
            ->where(fn ($query) => $query->whereNull('monthly_cost_currency')->orWhere('monthly_cost_currency', 'USD'))
            ->groupBy('provider_id')->selectRaw('provider_id, sum(monthly_cost) as total')->pluck('total', 'provider_id');
        $rows = [];
        foreach ($providers as $provider) {
            $mine = $bills->get($provider->id, collect());
            $previous = $mine->firstWhere('period', now()->subMonthNoOverflow()->format('Y-m'));
            $estimate = isset($estimates[$provider->id]) ? round((float) $estimates[$provider->id], 2) : null;
            $rows[] = [
                'provider' => $provider,
                'estimate' => $estimate,
                'current' => $mine->firstWhere('period', now()->format('Y-m')),
                'previous' => $previous,
                // Last month's invoice is a whole month, so it compares fairly with a month of list prices.
                'difference' => $previous !== null && $estimate !== null ? round($previous->amount - $estimate, 2) : null,
            ];
        }

        return $rows;
    }
}
