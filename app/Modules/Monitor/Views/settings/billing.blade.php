@extends('monitor::layouts.app')

@section('title', 'Plans & billing')
@section('breadcrumb', 'Plans & billing')

@section('content')
    @php
        $planStyles = [
            'free' => ['border' => 'border-line', 'icon' => 'bg-surface-muted text-muted'],
            'pro' => ['border' => 'border-primary ring-2 ring-primary', 'icon' => 'bg-primary-soft text-primary'],
            'team' => ['border' => 'border-info', 'icon' => 'bg-info-soft text-info'],
            'scale' => ['border' => 'border-warning', 'icon' => 'bg-warning-soft text-warning'],
        ];
        $billingStatusLabel = match($billingStatus) {
            'active' => 'Stripe active',
            'trialing' => 'Trialing',
            'past_due' => 'Payment due',
            'pending' => 'Awaiting confirmation',
            'canceled' => 'Canceled',
            default => 'Usage only',
        };
        $billingStatusTone = match($billingStatus) {
            'active', 'trialing' => 'green',
            'past_due', 'pending' => 'amber',
            'canceled' => 'red',
            default => 'slate',
        };
    @endphp

    <div class="space-y-6">
        <x-monitor::ui.page-header eyebrow="Workspace billing" eyebrow-icon="credit-card" title="Plans that scale with your signal." description="Keep the first 500k events free, then pay for the telemetry and retention your team actually uses.">
            <x-slot:actions><x-monitor::ui.button :href="route('monitor.settings.integrations')" variant="secondary"><x-monitor::icon name="code" class="h-4 w-4" />Integration setup</x-monitor::ui.button></x-slot:actions>
        </x-monitor::ui.page-header>

        <section class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1.3fr)_minmax(300px,0.7fr)]">
            <div class="ui-card px-6 py-6 sm:px-8">
                <div class="relative flex flex-col justify-between gap-6 sm:flex-row sm:items-center">
                    <div>
                        <div class="flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.16em] text-primary"><span class="h-1.5 w-1.5 rounded-full bg-success"></span>Current plan</div>
                        <h2 class="mt-3 text-2xl font-bold">{{ $currentPlan['name'] }} plan</h2>
                        <p class="mt-2 max-w-lg text-sm leading-6 text-muted">{{ $currentPlan['description'] }} Usage is measured by arrival time, within the current UTC calendar month.</p>
                    </div>
                    <div class="ui-card shadow-none shrink-0 bg-surface-muted px-5 py-4 sm:min-w-52">
                        <div class="flex items-end justify-between gap-4"><span class="text-xs font-semibold text-muted">Monthly signal usage</span><span class="text-lg font-bold">{{ $usagePercentage }}%</span></div>
                        <div class="ui-progress mt-3 bg-line"><span style="width: {{ $usagePercentage }}%"></span></div>
                        <p class="mt-2 text-[11px] text-subtle">{{ number_format($eventsThisMonth) }} of {{ number_format($currentPlan['event_limit']) }} events</p>
                        <p class="mt-3 max-w-xs text-[11px] leading-5 text-subtle">Accepted events count once. Retries do not add usage; deleting telemetry or archiving applications does not subtract it. Historical events received before metering was enabled are excluded.</p>
                    </div>
                </div>
            </div>
            <div class="ui-card p-5">
                <div class="flex items-center justify-between gap-3"><span class="text-xs font-bold text-ink dark:text-ink">Billing contact</span><x-monitor::ui.badge :tone="$billingStatusTone">{{ $billingStatusLabel }}</x-monitor::ui.badge></div>
                <p class="mt-4 text-sm font-bold text-ink dark:text-ink">{{ $billingOwner->name }}</p>
                <p class="mt-1 text-xs text-muted dark:text-subtle">{{ $billingOwner->email }}</p>
                @if($portalAvailable)
                    <form method="POST" action="{{ route('monitor.settings.billing.portal') }}" class="mt-5 border-t border-line pt-4 dark:border-line">
                        @csrf
                        <x-monitor::ui.button variant="quiet" size="sm">Manage billing in Stripe <x-monitor::icon name="external" class="h-3.5 w-3.5" /></x-monitor::ui.button>
                    </form>
                @elseif(! $stripeBillingConfigured)
                    <div class="mt-5 border-t border-line pt-4 text-[11px] leading-5 text-muted dark:border-line dark:text-subtle">Stripe checkout is ready to connect. Add the Stripe environment values to enable paid subscriptions; this preview does not charge you.</div>
                @else
                    <div class="mt-5 border-t border-line pt-4 text-[11px] leading-5 text-muted dark:border-line dark:text-subtle">Usage tracking is active. Stripe will appear here after the first paid subscription is confirmed.</div>
                @endif
            </div>
        </section>

        @if($usageState === 'warning')
            <section class="ui-alert ui-alert-warning block p-5 sm:p-6"><div class="flex gap-3"><span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-control bg-warning-soft text-warning"><x-monitor::icon name="alert" class="h-4 w-4" /></span><div><h2 class="text-sm font-bold text-warning dark:text-warning">Monthly event usage is at {{ $usagePercentage }}%</h2><p class="mt-1 max-w-2xl text-xs leading-5 text-warning dark:text-warning">You have {{ number_format($remainingEvents) }} events remaining this month. Upgrade now to keep headroom for traffic spikes.</p></div></div></section>
        @elseif($planLimitReached)
            <section class="ui-alert ui-alert-danger block p-5 sm:p-6"><div class="flex gap-3"><span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-control bg-danger-soft text-danger"><x-monitor::icon name="alert" class="h-4 w-4" /></span><div><h2 class="text-sm font-bold text-danger dark:text-danger">Monthly event limit reached</h2><p class="mt-1 max-w-2xl text-xs leading-5 text-danger dark:text-danger">New deliveries stay encrypted and retryable while the workspace is at its allowance. Upgrade the plan, then retry failed deliveries from ingestion diagnostics.</p></div></div></section>
        @endif

        <section>
            <div class="mb-4 flex items-end justify-between"><div><h2 class="text-base font-bold text-ink dark:text-ink">Choose your operating range</h2><p class="mt-1 text-xs text-muted dark:text-subtle">Every plan includes the universal event API and OpenTelemetry intake.</p></div><span class="hidden text-xs font-semibold text-subtle sm:block">Billed monthly · cancel anytime</span></div>
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-4">
                @foreach($plans as $key => $plan)
                    <article class="ui-card relative flex flex-col p-5 {{ $planStyles[$key]['border'] }}">
                        @if($key === 'pro')<span class="absolute -top-3 left-5 rounded-full bg-primary px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-on-primary">Most popular</span>@endif
                        <div class="flex items-center justify-between"><span class="flex h-9 w-9 items-center justify-center rounded-control {{ $planStyles[$key]['icon'] }}"><x-monitor::icon name="{{ $key === 'free' ? 'shield' : ($key === 'pro' ? 'activity' : ($key === 'team' ? 'users' : 'server')) }}" class="h-[18px] w-[18px]" /></span>@if($key === $currentPlanKey)<x-monitor::ui.badge tone="green">Current</x-monitor::ui.badge>@endif</div>
                        <h3 class="mt-5 text-base font-bold text-ink dark:text-ink">{{ $plan['name'] }}</h3>
                        <p class="mt-1 min-h-10 text-xs leading-5 text-muted dark:text-subtle">{{ $plan['description'] }}</p>
                        <p class="mt-5 text-3xl font-bold tracking-tight text-ink dark:text-ink">${{ number_format($plan['price']) }}<span class="text-xs font-medium text-subtle"> / month</span></p>
                        <div class="my-5 h-px bg-surface-muted dark:bg-surface-muted"></div>
                        <ul class="flex-1 space-y-3">@foreach($plan['features'] as $feature)<li class="flex gap-2 text-xs text-muted dark:text-muted"><x-monitor::icon name="check" class="mt-0.5 h-3.5 w-3.5 shrink-0 text-success" />{{ $feature }}</li>@endforeach</ul>
                        @if($key === $currentPlanKey)
                            <x-monitor::ui.button type="button" variant="secondary" disabled class="mt-6 w-full">Current plan</x-monitor::ui.button>
                        @elseif($key !== 'free' && in_array($key, $checkoutPlans, true) && ! $hasActiveSubscription)
                            <form method="POST" action="{{ route('monitor.settings.billing.checkout') }}" class="mt-6">
                                @csrf
                                <input type="hidden" name="plan" value="{{ $key }}">
                                <x-monitor::ui.button :variant="$key === 'pro' ? 'primary' : 'outline'" class="w-full">{{ $key === 'scale' ? 'Start Scale' : 'Upgrade to '.$plan['name'] }} <x-monitor::icon name="external" class="h-3.5 w-3.5" /></x-monitor::ui.button>
                            </form>
                        @elseif($portalAvailable)
                            <form method="POST" action="{{ route('monitor.settings.billing.portal') }}" class="mt-6">
                                @csrf
                                <x-monitor::ui.button variant="outline" class="w-full">Manage in Stripe <x-monitor::icon name="external" class="h-3.5 w-3.5" /></x-monitor::ui.button>
                            </form>
                        @else
                            <x-monitor::ui.button type="button" variant="secondary" disabled class="mt-6 w-full">{{ $stripeBillingConfigured ? 'Configure price' : 'Connect Stripe' }}</x-monitor::ui.button>
                        @endif
                    </article>
                @endforeach
            </div>
        </section>

        <section class="ui-alert border-primary/30 bg-primary-soft block p-5 sm:p-6"><div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"><div class="flex gap-3"><span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-control bg-primary-soft text-primary dark:bg-primary-soft dark:text-primary"><x-monitor::icon name="shield" class="h-4 w-4" /></span><div><h2 class="text-sm font-bold text-primary dark:text-primary">Predictable usage, no surprise overages.</h2><p class="mt-1 max-w-2xl text-xs leading-5 text-primary dark:text-primary">{{ config('app.name') }} can warn at 80% and pause non-critical ingestion at your plan limit. High-volume customers can add a metered event pack or a custom retention window.</p></div></div><x-monitor::ui.button href="mailto:billing@beacon.test" class="shrink-0">Talk to us <x-monitor::icon name="arrow-up-right" class="h-3.5 w-3.5" /></x-monitor::ui.button></div></section>
    </div>
@endsection
