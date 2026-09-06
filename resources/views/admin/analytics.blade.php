<x-layouts.app>
    <x-layouts.partials.heading
        :title="__('Business analytics')"
        :description="__('Private platform-wide growth, usage, revenue, and monetization signals.')"
    />

    <dl class="mt-8 grid grid-cols-2 gap-3 lg:grid-cols-4 2xl:grid-cols-8">
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
            <div class="rounded-xl border border-primary bg-primary p-4">
                <dt class="text-[11px] font-bold uppercase tracking-wide text-secondary">{{ $label }}</dt>
                <dd class="mt-2 text-2xl font-black text-primary">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>

    @php
        $signupMax = max(1, $trend->max('signups'));
        $deploymentMax = max(1, $trend->max('deployments'));
        $planMax = max(1, $plans->max());
    @endphp
    <div class="mt-6 grid gap-4 xl:grid-cols-[1fr_1fr_.8fr]">
        <section class="rounded-2xl border border-primary bg-primary p-5" aria-labelledby="signup-trend-title">
            <h2 id="signup-trend-title" class="font-black text-primary">{{ __('New users · 30 days') }}</h2>
            <div class="mt-5 flex h-32 items-end gap-1" role="img" aria-label="{{ __('Daily new user registrations') }}">
                @foreach ($trend as $day)
                    <div class="h-full min-w-0 flex-1 flex items-end" title="{{ $day['date'] }}: {{ $day['signups'] }}">
                        <div class="w-full rounded-t bg-ternary" style="height: {{ $day['signups'] === 0 ? 2 : max(8, ($day['signups'] / $signupMax) * 100) }}%"></div>
                    </div>
                @endforeach
            </div>
            <div class="mt-2 flex justify-between text-[10px] font-bold uppercase text-secondary"><span>{{ $trend->first()['date'] }}</span><span>{{ __('Today') }}</span></div>
        </section>

        <section class="rounded-2xl border border-primary bg-primary p-5" aria-labelledby="deployment-trend-title">
            <div class="flex items-start justify-between gap-3"><h2 id="deployment-trend-title" class="font-black text-primary">{{ __('Deployments · 30 days') }}</h2><strong class="text-primary">{{ number_format($totals['deployments_30d']) }}</strong></div>
            <div class="mt-5 flex h-32 items-end gap-1" role="img" aria-label="{{ __('Daily platform deployments') }}">
                @foreach ($trend as $day)
                    <div class="h-full min-w-0 flex-1 flex items-end" title="{{ $day['date'] }}: {{ $day['deployments'] }}">
                        <div class="w-full rounded-t bg-ternary" style="height: {{ $day['deployments'] === 0 ? 2 : max(8, ($day['deployments'] / $deploymentMax) * 100) }}%"></div>
                    </div>
                @endforeach
            </div>
            <div class="mt-2 flex justify-between text-[10px] font-bold uppercase text-secondary"><span>{{ $trend->first()['date'] }}</span><span>{{ __('Today') }}</span></div>
        </section>

        <section class="rounded-2xl border border-primary bg-primary p-5" aria-labelledby="plans-title">
            <h2 id="plans-title" class="font-black text-primary">{{ __('Plan distribution') }}</h2>
            <div class="mt-5 space-y-3">
                @foreach ($plans as $plan => $count)
                    <div>
                        <div class="flex justify-between text-xs"><span class="font-bold capitalize text-primary">{{ $plan }}</span><span class="text-secondary">{{ $count }}</span></div>
                        <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-secondary"><div class="h-full rounded-full bg-ternary" style="width: {{ ($count / $planMax) * 100 }}%"></div></div>
                    </div>
                @endforeach
            </div>
        </section>
    </div>

    <p class="mt-4 text-xs text-secondary">{{ __('MRR is an estimate from active base-plan prices and excludes taxes, refunds, discounts, and metered adjustments. Limit-block telemetry begins from this release.') }}</p>
</x-layouts.app>
