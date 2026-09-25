@extends('analytics::layouts.app')

@section('content')
    <div class="space-y-8">
        <x-signal.ui.page-header
            eyebrow="{{ $workspace->name }}"
            title="Analytics billing"
            description="Manage this workspace's Analytics plan separately from Deployer and Monitor."
        >
            <x-slot:actions>
                <x-signal.ui.button href="{{ route('analytics.workspaces.team', $workspace) }}" variant="secondary">Team access</x-signal.ui.button>
            </x-slot:actions>
        </x-signal.ui.page-header>

        @if (session('status'))
            <x-signal.ui.alert tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>
        @endif
        @if ($errors->has('billing'))
            <x-signal.ui.alert tone="danger" role="alert">{{ $errors->first('billing') }}</x-signal.ui.alert>
        @endif

        <x-signal.ui.panel as="section" class="space-y-4 p-6">
            <div>
                <h2 class="text-lg font-extrabold text-ink">Current Analytics plan</h2>
                <p class="mt-1 text-sm text-muted">Only provider-confirmed subscription changes update this workspace's entitlement.</p>
            </div>
            <dl class="grid gap-4 text-sm sm:grid-cols-2">
                <div><dt class="font-semibold text-muted">Plan</dt><dd class="mt-1 font-bold text-ink">{{ $resolution->available ? ($resolution->planName ?? $resolution->planKey) : 'Unverified' }}</dd></div>
                <div><dt class="font-semibold text-muted">Status</dt><dd class="mt-1 font-bold text-ink">{{ ucfirst(str_replace('_', ' ', $resolution->subscriptionStatus ?? 'Unavailable')) }}</dd></div>
                @if ($currentSubscription?->current_period_ends_at)
                    <div><dt class="font-semibold text-muted">Current period ends</dt><dd class="mt-1 font-bold text-ink">{{ $currentSubscription->current_period_ends_at->utc()->format('M j, Y') }} UTC</dd></div>
                @endif
                @if ($currentSubscription?->cancel_at)
                    <div><dt class="font-semibold text-muted">Cancellation scheduled</dt><dd class="mt-1 font-bold text-ink">{{ $currentSubscription->cancel_at->utc()->format('M j, Y') }} UTC</dd></div>
                @endif
            </dl>

            @if ($managementAvailable)
                <div class="flex flex-wrap gap-3 border-t border-line pt-4">
                    <form method="POST" action="{{ route('analytics.workspaces.billing.portal', $workspace) }}">
                        @csrf
                        <x-signal.ui.input type="hidden" name="idempotency_key" :value="$idempotencyKey" />
                        <x-signal.ui.button type="submit" variant="secondary">Manage payment details</x-signal.ui.button>
                    </form>
                    @if (!$currentSubscription?->cancel_at && in_array($currentSubscription?->status, ['active', 'trialing', 'past_due'], true))
                        <form method="POST" action="{{ route('analytics.workspaces.billing.cancel-subscription', $workspace) }}">
                            @csrf
                            <x-signal.ui.input type="hidden" name="idempotency_key" :value="$idempotencyKey" />
                            <x-signal.ui.button type="submit" variant="secondary">Cancel at period end</x-signal.ui.button>
                        </form>
                    @endif
                </div>
            @endif
        </x-signal.ui.panel>

        <x-signal.ui.panel as="section" class="space-y-5 p-6">
            <div>
                <h2 class="text-lg font-extrabold text-ink">Available plans</h2>
                <p class="mt-1 text-sm text-muted">Each price and allowance must be configured and confirmed for Analytics before checkout opens. The provider confirms any tax and the final total before payment.</p>
            </div>

            @if (!$checkoutAvailable)
                <x-signal.ui.alert tone="info">New Analytics purchases are not available for this workspace.</x-signal.ui.alert>
            @else
                <div class="grid gap-4 md:grid-cols-2">
                    @foreach ($plans as $plan)
                        <x-signal.ui.card class="space-y-3 p-5">
                            <div>
                                <h3 class="font-extrabold text-ink">{{ $plan['name'] }}</h3>
                                @if ($plan['description'])<p class="mt-1 text-sm text-muted">{{ $plan['description'] }}</p>@endif
                            </div>
                            <p class="text-sm font-bold text-ink">{{ strtoupper($plan['currency']) }} {{ number_format($plan['amount'] / 100, 2) }} / {{ $plan['interval_count'] > 1 ? $plan['interval_count'].' ' : '' }}{{ $plan['interval'] }}{{ $plan['interval_count'] > 1 ? 's' : '' }}</p>
                            <form method="POST" action="{{ route('analytics.workspaces.billing.checkout', $workspace) }}">
                                @csrf
                                <x-signal.ui.input type="hidden" name="plan" :value="$plan['key']" />
                                <x-signal.ui.input type="hidden" name="idempotency_key" :value="$idempotencyKey" />
                                <x-signal.ui.button type="submit" variant="primary">Choose {{ $plan['name'] }}</x-signal.ui.button>
                            </form>
                        </x-signal.ui.card>
                    @endforeach
                </div>
            @endif
        </x-signal.ui.panel>
    </div>
@endsection
