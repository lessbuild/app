{{-- The Business page: headline numbers, the daily charts, the sign-up funnel and accounts per tier. --}}
@php($money = fn (int $cents): string => '$'.number_format($cents / 100, 2))
@php($cohort = max(1, $funnel[0]['accounts'] ?? 0))
<x-filament-panels::page>
    <div class="grid gap-6 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            [__('Estimated monthly revenue'), $money($totals['mrr_cents']), trans_choice(':count paying account|:count paying accounts', $totals['paid_accounts'])],
            [__('Accounts'), number_format($totals['accounts']), __(':rate% pay', ['rate' => $totals['accounts'] === 0 ? 0 : round($totals['paid_accounts'] / $totals['accounts'] * 100, 1)])],
            [__('People'), number_format($totals['users']), __(':active signed in, :new new in the last 30 days', ['active' => number_format($totals['active_users']), 'new' => number_format($totals['signups'])])],
            [__('Churned in 30 days'), number_format($totals['churned']), __('Accounts whose paid tier ended')],
        ] as [$label, $value, $detail])
            <x-filament::section>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ $value }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $detail }}</p>
            </x-filament::section>
        @endforeach
    </div>

    <div class="grid gap-6 md:grid-cols-3">
        @foreach (['signups', 'deploys', 'checks'] as $metric)
            @livewire(\App\Filament\Widgets\BusinessTrend::class, ['metric' => $metric], key('trend-'.$metric))
        @endforeach
    </div>

    <x-filament::section :heading="__('Sign-up funnel')" :description="__('Accounts created in the last 30 days, and how many have reached each step. Sample projects don’t count.')">
        <ol class="grid gap-3">
            @foreach ($funnel as $step)
                @php($share = (int) round($step['accounts'] / $cohort * 100))
                <li class="grid items-center gap-x-4 gap-y-1 text-sm sm:grid-cols-[12rem_1fr_7rem]">
                    <span class="font-medium">{{ $step['label'] }}</span>
                    <span class="h-2 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10" role="meter" aria-valuemin="0" aria-valuemax="{{ $cohort }}" aria-valuenow="{{ $step['accounts'] }}" aria-label="{{ __(':step: :count accounts', ['step' => $step['label'], 'count' => $step['accounts']]) }}">
                        <span class="block h-full rounded-full bg-primary-500" style="width: {{ $share }}%"></span>
                    </span>
                    <span class="tabular-nums text-gray-500 sm:text-right dark:text-gray-400"><span class="font-semibold text-gray-950 dark:text-white">{{ number_format($step['accounts']) }}</span> · {{ $share }}%</span>
                </li>
            @endforeach
        </ol>
    </x-filament::section>

    <div class="grid gap-6 lg:grid-cols-2">
        @foreach ($services as $service)
            <x-filament::section :heading="__(':service tiers', ['service' => $service['name']])">
                <ul class="divide-y divide-gray-200 text-sm dark:divide-white/10">
                    @foreach ($service['tiers'] as $tier)
                        <li class="flex items-center justify-between gap-3 py-2"><span>{{ $tier['name'] }}</span><span class="tabular-nums">{{ number_format($tier['accounts']) }}</span></li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
