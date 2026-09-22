<x-layouts.app>
    <x-layouts.partials.heading
        icon="cog"
        :title="__('Billing')"
        :description="__('Plans and payments for :workspace.', ['workspace' => auth()->user()->currentOrganization->name])"
    >
        <x-slot:buttons>
            <x-ui.button :href="route('costs.index')" variant="secondary">{{ __('Cost visibility') }}</x-ui.button>
        </x-slot:buttons>
    </x-layouts.partials.heading>

    <x-ui.local-nav class="mt-6" :label="__('Billing sections')">
        <a href="#billing-insights" class="ui-local-nav__link">{{ __('Insights') }}</a>
        <a href="#billing-current-plan" class="ui-local-nav__link">{{ __('Current plan') }}</a>
        <a href="#billing-options" class="ui-local-nav__link">{{ __('Plan options') }}</a>
    </x-ui.local-nav>

    <div class="mt-6 space-y-3">
        @if (request('checkout') === 'success')
            <x-ui.alert tone="success" role="status">{{ __('Checkout complete. Stripe is activating your subscription.') }}</x-ui.alert>
        @endif
        @if (request('checkout') === 'cancelled')
            <x-ui.alert tone="info" role="status">{{ __('Checkout was cancelled. Nothing was charged.') }}</x-ui.alert>
        @endif
        @if (session('status'))
            <x-ui.alert tone="info" role="status">{{ session('status') }}</x-ui.alert>
        @endif
        @error('plan')
            <x-ui.alert tone="warning" role="alert">{{ $message }}</x-ui.alert>
        @enderror
    </div>

    <x-ui.insights
        id="billing-insights"
        class="mt-6 scroll-mt-24"
        :summary="__('Current plan: :plan', ['plan' => $plans[$currentPlan]['name']])"
    >
        <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.stat
                :label="__('Current plan')"
                :value="$plans[$currentPlan]['name']"
                :description="__('Workspace entitlement level.')"
            />
            <x-ui.stat
                :label="__('Billing cycle')"
                :value="$currentPlan === 'free' ? __('No subscription') : ucfirst($currentInterval)"
                :description="__('The interval used for paid plan changes.')"
            />
            <x-ui.stat
                :label="__('Plan options')"
                :value="count($plans)"
                :description="__('Available plans for this installation.')"
            />
            <x-ui.stat
                :label="__('API limit')"
                :value="number_format($plans[$currentPlan]['limits']['api_requests_per_minute']).'/min'"
                :description="__('Requests allowed by the current plan.')"
            />
        </dl>
    </x-ui.insights>

    <x-ui.card id="billing-current-plan" class="mt-8 scroll-mt-24 overflow-hidden">
        <div class="flex flex-wrap items-start justify-between gap-5 p-6">
            <div>
                <p class="ui-eyebrow text-[0.65rem]">{{ __('Current workspace plan') }}</p>
                <div class="mt-2 flex flex-wrap items-center gap-3">
                    <h2 class="text-3xl font-black text-ink">{{ $plans[$currentPlan]['name'] }}</h2>
                    @if ($currentPlan !== 'free')
                        <x-ui.badge tone="accent">{{ ucfirst($currentInterval) }}</x-ui.badge>
                    @endif
                </div>
                @if ($subscription?->onTrial())
                    <p class="mt-2 text-sm text-muted">{{ __('Trial ends :date.', ['date' => $subscription->trial_ends_at->toFormattedDateString()]) }}</p>
                @elseif ($subscription?->onGracePeriod())
                    <p class="mt-2 text-sm font-semibold text-amber-700">{{ __('Cancels :date.', ['date' => $subscription->ends_at->toFormattedDateString()]) }}</p>
                @endif
            </div>
            @if ($canManageBilling && $billingUser->stripe_id)
                <form method="POST" action="{{ route('billing.portal') }}">
                    @csrf
                    <x-ui.button type="submit" variant="secondary">{{ __('Invoices & payment method') }}</x-ui.button>
                </form>
            @endif
        </div>

        @if ($subscription && $canManageBilling)
            <div class="flex flex-wrap items-center gap-3 border-t border-line bg-surface-muted px-6 py-4">
                @if ($subscription->onGracePeriod())
                    <form method="POST" action="{{ route('billing.resume') }}">
                        @csrf
                        <x-ui.button type="submit" variant="primary">{{ __('Resume subscription') }}</x-ui.button>
                    </form>
                @else
                    <form method="POST" action="{{ route('billing.cancel') }}" onsubmit="return confirm({{ Illuminate\Support\Js::from(__('Cancel at the end of this billing period?')) }})">
                        @csrf
                        <x-ui.button type="submit" variant="secondary">{{ __('Cancel subscription') }}</x-ui.button>
                    </form>
                @endif
            </div>
        @endif
    </x-ui.card>

    @unless ($canManageBilling)
        <x-ui.alert class="mt-6" tone="info">{{ __('Only the workspace owner can change its subscription.') }}</x-ui.alert>
    @endunless
    @unless ($stripeReady)
        <x-ui.alert class="mt-6" tone="warning">
            <p class="font-bold">{{ __('Payments are almost ready') }}</p>
            <p class="mt-1">{{ __('The site owner still needs to configure Stripe API keys and Price IDs.') }}</p>
        </x-ui.alert>
    @endunless

    <div class="mt-8 flex justify-center">
        <div class="inline-flex rounded-xl border border-line bg-surface-muted p-1" role="group" aria-label="{{ __('Billing interval') }}">
            <a href="{{ route('billing.index', ['interval' => 'monthly']) }}" @class(['rounded-lg px-5 py-2.5 text-sm font-bold transition', 'bg-primary text-white' => $selectedInterval === 'monthly', 'text-muted hover:bg-surface' => $selectedInterval !== 'monthly']) @if ($selectedInterval === 'monthly') aria-current="page" @endif>{{ __('Monthly') }}</a>
            <a href="{{ route('billing.index', ['interval' => 'yearly']) }}" @class(['rounded-lg px-5 py-2.5 text-sm font-bold transition', 'bg-primary text-white' => $selectedInterval === 'yearly', 'text-muted hover:bg-surface' => $selectedInterval !== 'yearly']) @if ($selectedInterval === 'yearly') aria-current="page" @endif>{{ __('Yearly · 2 months free') }}</a>
        </div>
    </div>

    <div id="billing-options" class="mt-6 scroll-mt-24 grid gap-5 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($plans as $key => $plan)
            @php
                $shownPrice = $selectedInterval === 'yearly' ? $plan['yearly_price'] : $plan['price'];
                $priceId = $plan[$selectedInterval.'_price_id'] ?? null;
            @endphp
            <x-ui.card @class(['relative flex flex-col p-6', 'border-2 border-primary' => $key === 'pro'])>
                @if ($key === 'pro')
                    <x-ui.badge tone="accent" class="absolute -top-3 left-5">{{ __('Most popular') }}</x-ui.badge>
                @endif
                <h2 class="text-xl font-black text-ink">{{ $plan['name'] }}</h2>
                <p class="mt-2 min-h-12 text-sm text-muted">{{ $plan['description'] }}</p>
                <p class="mt-5 text-ink"><span class="text-4xl font-black">${{ $shownPrice }}</span><span class="text-muted">{{ $shownPrice ? ($selectedInterval === 'yearly' ? __('/year') : __('/month')) : __(' forever') }}</span></p>
                @if ($selectedInterval === 'yearly' && $shownPrice)
                    <p class="mt-1 text-xs font-semibold text-primary">{{ __('Equivalent to $:price/month', ['price' => number_format($shownPrice / 12, 2)]) }}</p>
                @endif
                <ul class="my-6 flex-1 space-y-2 text-sm text-muted">
                    @foreach ($plan['features'] as $feature)
                        <li class="flex gap-2"><span class="font-bold text-primary" aria-hidden="true">✓</span><span>{{ $feature }}</span></li>
                    @endforeach
                    <li class="flex gap-2"><span class="font-bold text-primary" aria-hidden="true">✓</span><span>{{ number_format($plan['limits']['api_requests_per_minute']) }} {{ __('API requests per minute') }}</span></li>
                </ul>

                @if ($key === $currentPlan)
                    <x-ui.button type="button" variant="secondary" class="w-full justify-center" disabled>{{ __('Current plan') }}</x-ui.button>
                @elseif ($key !== 'free' && $subscription)
                    <form method="POST" action="{{ route('billing.portal') }}">
                        @csrf
                        <x-ui.button type="submit" variant="primary" class="w-full justify-center" :disabled="! $canManageBilling">{{ __('Change in Stripe') }}</x-ui.button>
                    </form>
                @elseif ($key !== 'free')
                    <form method="POST" action="{{ route('billing.checkout', $key) }}">
                        @csrf
                        <input type="hidden" name="interval" value="{{ $selectedInterval }}">
                        <x-ui.button type="submit" variant="primary" class="w-full justify-center" :disabled="! $canManageBilling || ! $stripeReady || blank($priceId)">{{ __('Start 14-day trial') }}</x-ui.button>
                    </form>
                @endif
            </x-ui.card>
        @endforeach
    </div>

    <p class="mt-6 text-center text-sm text-muted">{{ __('Annual plans include roughly two months free. Team and Business include their listed seats; configured extra seats are billed automatically. Unlimited is subject to fair use.') }}</p>
</x-layouts.app>
