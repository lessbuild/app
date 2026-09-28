@php($money = fn (int $cents): string => '$'.number_format($cents / 100, 2))
<x-signal.layouts.admin :title="__('Business')" :description="__('Worked out at :time and cached for five minutes. Revenue is estimated from the chosen tiers and add-ons at catalogue prices; metered usage isn’t included.', ['time' => \Carbon\CarbonImmutable::parse($generated_at)->toDayDateTimeString()])">
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-signal.ui.stat :label="__('Estimated monthly revenue')" :value="$money($totals['mrr_cents'])" :description="trans_choice(':count paying account|:count paying accounts', $totals['paid_accounts'])" />
        <x-signal.ui.stat :label="__('Accounts')" :value="number_format($totals['accounts'])" :description="__(':rate% pay', ['rate' => $totals['accounts'] === 0 ? 0 : round($totals['paid_accounts'] / $totals['accounts'] * 100, 1)])" />
        <x-signal.ui.stat :label="__('People')" :value="number_format($totals['users'])" :description="__(':active signed in, :new new in the last 30 days', ['active' => number_format($totals['active_users']), 'new' => number_format($totals['signups'])])" />
        <x-signal.ui.stat :label="__('Churned in 30 days')" :value="number_format($totals['churned'])" :description="__('Accounts whose paid tier ended')" />
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        @foreach ([['signups', __('Sign-ups per day'), __('sign-ups')], ['deploys', __('Deploys per day'), __('deploys')], ['checks', __('Monitoring checks per day'), __('checks')]] as [$key, $label, $unit])
            <x-signal.ui.card class="p-5">
                <h2 class="text-sm font-extrabold text-ink">{{ $label }}</h2>
                <x-signal.ui.bar-chart :label="$label" :points="array_map(fn (array $day): array => ['label' => $day['date'], 'value' => $day[$key]], $trend)" :unit="$unit" />
            </x-signal.ui.card>
        @endforeach
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        @foreach ($services as $service)
            <x-signal.ui.table :caption="__(':service tiers', ['service' => $service['name']])">
                <x-slot:head><tr><th scope="col">{{ __('Tier') }}</th><th scope="col">{{ __('Accounts') }}</th></tr></x-slot:head>
                @foreach ($service['tiers'] as $tier)
                    <tr><td>{{ $tier['name'] }}</td><td>{{ number_format($tier['accounts']) }}</td></tr>
                @endforeach
            </x-signal.ui.table>
        @endforeach
    </div>
</x-signal.layouts.admin>
