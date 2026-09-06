<x-layouts.app>
    <x-layouts.partials.heading icon="cog" :title="__('Billing')" :description="__('Plans and payments for :workspace.', ['workspace' => auth()->user()->currentOrganization->name])" />

    @if (request('checkout') === 'success')<div class="mt-6 rounded-xl border border-green-300 bg-green-50 p-4 text-green-800">{{ __('Checkout complete. Stripe is activating your subscription.') }}</div>@endif
    @if (request('checkout') === 'cancelled')<div class="mt-6 rounded-xl border border-primary bg-primary p-4 text-secondary">{{ __('Checkout was cancelled. Nothing was charged.') }}</div>@endif
    @if (session('status'))<div class="mt-6 rounded-xl border border-primary bg-primary p-4 text-secondary">{{ session('status') }}</div>@endif
    @error('plan')<div class="mt-6 rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-900">{{ $message }}</div>@enderror

    <section class="mt-8 overflow-hidden rounded-2xl border border-primary bg-primary shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-5 p-6">
            <div><p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Current workspace plan') }}</p><div class="mt-2 flex flex-wrap items-baseline gap-3"><h2 class="text-3xl font-black text-primary">{{ $plans[$currentPlan]['name'] }}</h2>@if($currentPlan !== 'free')<span class="rounded-full bg-secondary px-3 py-1 text-xs font-bold text-secondary">{{ ucfirst($currentInterval) }}</span>@endif</div>
                @if($subscription?->onTrial())<p class="mt-2 text-sm text-secondary">{{ __('Trial ends :date.', ['date' => $subscription->trial_ends_at->toFormattedDateString()]) }}</p>@elseif($subscription?->onGracePeriod())<p class="mt-2 text-sm font-semibold text-amber-700">{{ __('Cancels :date.', ['date' => $subscription->ends_at->toFormattedDateString()]) }}</p>@endif
            </div>
            @if($canManageBilling && $billingUser->stripe_id)<form method="POST" action="{{ route('billing.portal') }}">@csrf<button type="submit" class="button primary">{{ __('Invoices & payment method') }}</button></form>@endif
        </div>
        @if($subscription && $canManageBilling)<div class="flex flex-wrap items-center gap-3 border-t border-primary px-6 py-4">@if($subscription->onGracePeriod())<form method="POST" action="{{ route('billing.resume') }}">@csrf<button type="submit" class="button primary">{{ __('Resume subscription') }}</button></form>@else<form method="POST" action="{{ route('billing.cancel') }}" onsubmit="return confirm({{ Illuminate\Support\Js::from(__('Cancel at the end of this billing period?')) }})">@csrf<button type="submit" class="button secondary">{{ __('Cancel subscription') }}</button></form>@endif</div>@endif
    </section>

    @unless($canManageBilling)<div class="mt-6 rounded-xl border border-primary bg-primary p-4 text-secondary">{{ __('Only the workspace owner can change its subscription.') }}</div>@endunless
    @unless ($stripeReady)<div class="mt-6 rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-900"><p class="font-bold">{{ __('Payments are almost ready') }}</p><p class="mt-1">{{ __('The site owner still needs to configure Stripe API keys and Price IDs.') }}</p></div>@endunless

    <div class="mt-8 flex justify-center"><div class="inline-flex rounded-xl border border-primary bg-primary p-1"><a href="{{ route('billing.index', ['interval' => 'monthly']) }}" @class(['rounded-lg px-5 py-2 text-sm font-bold', 'bg-ternary text-white' => $selectedInterval === 'monthly', 'text-secondary' => $selectedInterval !== 'monthly'])>{{ __('Monthly') }}</a><a href="{{ route('billing.index', ['interval' => 'yearly']) }}" @class(['rounded-lg px-5 py-2 text-sm font-bold', 'bg-ternary text-white' => $selectedInterval === 'yearly', 'text-secondary' => $selectedInterval !== 'yearly'])>{{ __('Yearly · 2 months free') }}</a></div></div>

    <div class="mt-6 grid gap-5 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($plans as $key => $plan)
            @php($shownPrice = $selectedInterval === 'yearly' ? $plan['yearly_price'] : $plan['price'])
            @php($priceId = $plan[$selectedInterval.'_price_id'] ?? null)
            <article @class(['relative flex flex-col rounded-2xl border bg-primary p-6 shadow-sm', 'border-ternary ring-2 ring-ternary' => $key === 'pro', 'border-primary' => $key !== 'pro'])>
                @if($key === 'pro')<span class="absolute -top-3 left-5 rounded-full bg-ternary px-3 py-1 text-xs font-bold uppercase text-white">{{ __('Most popular') }}</span>@endif
                <h2 class="text-xl font-black text-primary">{{ $plan['name'] }}</h2><p class="mt-2 min-h-12 text-sm text-secondary">{{ $plan['description'] }}</p>
                <p class="mt-5 text-primary"><span class="text-4xl font-black">${{ $shownPrice }}</span><span class="text-secondary">{{ $shownPrice ? ($selectedInterval === 'yearly' ? __('/year') : __('/month')) : __(' forever') }}</span></p>
                @if($selectedInterval === 'yearly' && $shownPrice)<p class="mt-1 text-xs font-semibold text-ternary">{{ __('Equivalent to $:price/month', ['price' => number_format($shownPrice / 12, 2)]) }}</p>@endif
                <ul class="my-6 flex-1 space-y-2 text-sm text-secondary">@foreach($plan['features'] as $feature)<li class="flex gap-2"><span class="font-bold text-ternary">✓</span>{{ $feature }}</li>@endforeach</ul>
                @if($key === $currentPlan)<button type="button" disabled class="button tertiary w-full justify-center">{{ __('Current plan') }}</button>
                @elseif($key !== 'free' && $subscription)<form method="POST" action="{{ route('billing.portal') }}">@csrf<button type="submit" class="button primary w-full justify-center" @disabled(!$canManageBilling)>{{ __('Change in Stripe') }}</button></form>
                @elseif($key !== 'free')<form method="POST" action="{{ route('billing.checkout', $key) }}">@csrf<input type="hidden" name="interval" value="{{ $selectedInterval }}"><button type="submit" class="button primary w-full justify-center" @disabled(!$canManageBilling || !$stripeReady || blank($priceId))>{{ __('Start 14-day trial') }}</button></form>
                @endif
            </article>
        @endforeach
    </div>
    <p class="mt-6 text-center text-sm text-secondary">{{ __('Annual plans include roughly two months free. Team and Business include their listed seats; configured extra seats are billed automatically. Unlimited is subject to fair use.') }}</p>
</x-layouts.app>
