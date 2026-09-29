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

    {{-- Where the last 30 days' new accounts got to. Each bar is a share of those accounts; the drop is from the step before. --}}
    @php($cohort = max(1, $funnel[0]['accounts'] ?? 0))
    <x-signal.ui.card as="section" class="grid gap-4 p-5" aria-labelledby="funnel-heading">
        <div>
            <h2 id="funnel-heading" class="text-sm font-extrabold text-ink">{{ __('Sign-up funnel') }}</h2>
            <p class="mt-1 text-xs text-muted">{{ __('Accounts created in the last 30 days, and how many have reached each step. Sample projects don’t count.') }}</p>
        </div>
        <ol class="grid gap-2.5">
            @foreach ($funnel as $step)
                @php($share = round($step['accounts'] / $cohort * 100))
                @php($previous = $loop->first ? null : $funnel[$loop->index - 1]['accounts'])
                <li class="grid items-center gap-x-3 gap-y-1 text-sm sm:grid-cols-[12rem_1fr_7rem]">
                    <span class="font-semibold text-ink">{{ $step['label'] }}</span>
                    <x-signal.ui.progress :value="$step['accounts']" :max="$cohort" role="meter" :label="__(':step: :count accounts', ['step' => $step['label'], 'count' => $step['accounts']])" />
                    <span class="tabular-nums text-muted sm:text-right">
                        <span class="font-bold text-ink">{{ number_format($step['accounts']) }}</span> · {{ $share }}%
                        @if ($previous !== null && $previous > 0 && $previous > $step['accounts'])<span class="sr-only">, {{ __(':drop fewer than the step before', ['drop' => $previous - $step['accounts']]) }}</span>@endif
                    </span>
                </li>
            @endforeach
        </ol>
    </x-signal.ui.card>

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
