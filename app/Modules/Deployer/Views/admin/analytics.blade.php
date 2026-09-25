<x-layouts.app>
    <x-signal.ui.page-header
        icon="chart-bar"
        :title="__('Business analytics')"
        :description="__('Private platform-wide growth, usage, revenue, and monetization signals.')"
    />

    @php
        $analyticsSummaryOpen = $totals['pending_access_requests'] > 0 || $totals['denials_30d'] > 0;
    @endphp

    <x-signal.ui.insights
        id="admin-analytics-summary"
        class="mt-8"
        :open="$analyticsSummaryOpen"
        :mobile-open="$analyticsSummaryOpen"
        :summary="number_format($totals['users']).' '.__('users').' · '.number_format($totals['pending_access_requests']).' '.__('pending access')"
    >
        <dl class="ui-insight-grid grid grid-cols-2 gap-3 lg:grid-cols-4 2xl:grid-cols-8">
            @foreach ([
                __('Users') => number_format($totals['users']),
                __('Active 30d') => number_format($totals['active_users']),
                __('Workspaces') => number_format($totals['workspaces']),
                __('Paid') => number_format($totals['paid_workspaces']),
                __('Conversion') => $totals['conversion_rate'].'%',
                __('Estimated MRR') => '$'.number_format($totals['estimated_mrr'], 2),
                __('Churn 30d') => number_format($totals['churned_30d']),
                __('Limit blocks') => number_format($totals['denials_30d']),
                __('Access requests') => number_format($totals['pending_access_requests']),
            ] as $label => $value)
                <x-signal.ui.stat :label="$label" :value="$value" class="ui-card" />
            @endforeach
        </dl>
    </x-signal.ui.insights>

    @php
        $signupMax = max(1, $trend->max('signups'));
        $deploymentMax = max(1, $trend->max('deployments'));
        $planMax = max(1, $plans->max());
    @endphp

    <div class="mt-6 grid gap-4 xl:grid-cols-[1fr_1fr_.8fr]">
        <x-signal.ui.card class="p-5 sm:p-6" aria-labelledby="signup-trend-title">
            <h2 id="signup-trend-title" class="font-extrabold text-ink">{{ __('New users · 30 days') }}</h2>
            <div class="mt-5 flex h-32 items-end gap-1" role="img" aria-label="{{ __('Daily new user registrations') }}">
                @foreach ($trend as $day)
                    <div class="flex h-full min-w-0 flex-1 items-end" title="{{ $day['date'] }}: {{ $day['signups'] }}">
                        <div class="ui-chart-bar" style="height: {{ $day['signups'] === 0 ? 2 : max(8, ($day['signups'] / $signupMax) * 100) }}%"></div>
                    </div>
                @endforeach
            </div>
            <div class="mt-2 flex justify-between text-[10px] font-bold uppercase text-muted"><span>{{ $trend->first()['date'] }}</span><span>{{ __('Today') }}</span></div>
        </x-signal.ui.card>

        <x-signal.ui.card class="p-5 sm:p-6" aria-labelledby="deployment-trend-title">
            <div class="flex items-start justify-between gap-3">
                <h2 id="deployment-trend-title" class="font-extrabold text-ink">{{ __('Deployments · 30 days') }}</h2>
                <strong class="text-ink">{{ number_format($totals['deployments_30d']) }}</strong>
            </div>
            <div class="mt-5 flex h-32 items-end gap-1" role="img" aria-label="{{ __('Daily platform deployments') }}">
                @foreach ($trend as $day)
                    <div class="flex h-full min-w-0 flex-1 items-end" title="{{ $day['date'] }}: {{ $day['deployments'] }}">
                        <div class="ui-chart-bar" style="height: {{ $day['deployments'] === 0 ? 2 : max(8, ($day['deployments'] / $deploymentMax) * 100) }}%"></div>
                    </div>
                @endforeach
            </div>
            <div class="mt-2 flex justify-between text-[10px] font-bold uppercase text-muted"><span>{{ $trend->first()['date'] }}</span><span>{{ __('Today') }}</span></div>
        </x-signal.ui.card>

        <x-signal.ui.card class="p-5 sm:p-6" aria-labelledby="plans-title">
            <h2 id="plans-title" class="font-extrabold text-ink">{{ __('Plan distribution') }}</h2>
            <div class="mt-5 space-y-3">
                @foreach ($plans as $plan => $count)
                    <div>
                        <div class="flex justify-between text-xs"><span class="font-bold capitalize text-ink">{{ $plan }}</span><span class="text-muted">{{ $count }}</span></div>
                        <x-signal.ui.progress class="mt-1.5" :label="__('Workspace share for :plan', ['plan' => $plan])" :value="($count / max(1, $planMax)) * 100" />
                    </div>
                @endforeach
            </div>
        </x-signal.ui.card>
    </div>

    <x-signal.ui.alert class="mt-4" tone="info">{{ __('MRR is an estimate from active base-plan prices and excludes taxes, refunds, discounts, and metered adjustments. Limit-block telemetry begins from this release.') }}</x-signal.ui.alert>
</x-layouts.app>
