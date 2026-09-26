<x-layouts.core :title="__('Pricing')" :description="__('Simple monthly or annual pricing for :app.', ['app' => config('app.name')])" :canonical="route('pricing')" :indexable="true" :livewire="false">
    <a href="#main-content" class="ui-skip-link">{{ __('Skip to main content') }}</a>
    <x-layouts.public-header navigation-label="Pricing navigation" />
    <main id="main-content" tabindex="-1" class="min-h-screen bg-page px-4 py-10 sm:px-6 lg:px-8" x-data="{ interval: 'yearly' }">
        <div class="mx-auto max-w-7xl">
            <header class="mx-auto max-w-3xl py-12 text-center"><p class="ui-eyebrow">{{ __('Simple pricing') }}</p><h1 class="mt-4 text-4xl font-extrabold tracking-tight text-ink sm:text-6xl">{{ __('From first push to serious scale.') }}</h1><p class="mt-5 text-lg text-muted">{{ __('Deploy to your own cloud with previews, rollbacks, monitoring and backups in one calm control plane.') }}</p>
                <div class="mt-7 inline-flex rounded-control border border-line bg-surface-muted p-1"><x-signal.ui.button variant="stateful" type="button" @click="interval='monthly'" x-bind:class="interval==='monthly' ? 'ui-btn-primary' : 'ui-btn-quiet'" x-bind:aria-pressed="(interval === 'monthly').toString()" class="ui-btn ui-btn-sm">{{ __('Monthly') }}</x-signal.ui.button><x-signal.ui.button variant="stateful" type="button" @click="interval='yearly'" x-bind:class="interval==='yearly' ? 'ui-btn-primary' : 'ui-btn-quiet'" x-bind:aria-pressed="(interval === 'yearly').toString()" class="ui-btn ui-btn-sm">{{ __('Yearly · save 2 months') }}</x-signal.ui.button></div>
            </header>
            <div id="pricing-plans" class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                @foreach($plans as $key => $plan)
                    @php
                        $visibleFeatures = array_slice($plan['features'], 0, 3);
                        $additionalFeatures = array_slice($plan['features'], 3);
                    @endphp
                    <x-signal.ui.card data-pricing-plan @class(['relative flex flex-col p-7', 'border-2 ring-2' => $key === 'pro']) @style(['border-color: var(--ui-primary); --tw-ring-color: var(--ui-primary)' => $key === 'pro'])>
                        @if($key === 'pro')<x-signal.ui.badge class="absolute -top-3 left-6" tone="accent">{{ __('Most popular') }}</x-signal.ui.badge>@endif
                        <h2 class="text-xl font-extrabold text-ink">{{ $plan['name'] }}</h2><p class="mt-2 min-h-12 text-muted">{{ $plan['description'] }}</p>
                        <p class="mt-7 text-ink"><span class="text-4xl font-extrabold" x-text="interval === 'yearly' ? '${{ $plan['yearly_price'] }}' : '${{ $plan['price'] }}'">${{ $plan['yearly_price'] }}</span><span class="text-muted" x-text="{{ $plan['price'] ? "interval === 'yearly' ? '/year' : '/month'" : "' forever'" }}">{{ $plan['price'] ? __('/year') : __(' forever') }}</span></p>
                        @if($plan['price'])<p x-show="interval==='yearly'" class="mt-1 text-xs font-semibold text-ink">{{ __('Equivalent to $:price/month', ['price' => number_format($plan['yearly_price'] / 12, 2)]) }}</p>@endif
                        <ul class="my-7 flex-1 space-y-3 text-muted">@foreach($visibleFeatures as $feature)<li class="flex gap-3"><span class="font-bold text-ink" aria-hidden="true">✓</span>{{ $feature }}</li>@endforeach</ul>
                        <details class="ui-responsive-details mt-3 border-t border-line pt-3" open data-responsive-details data-responsive-details-mobile-open="false">
                            <summary class="cursor-pointer list-none text-sm font-bold text-ink">{{ __('See all features and limits') }}</summary>
                            <div class="ui-responsive-details__content mt-3 space-y-3 text-sm text-muted">
                                @foreach($additionalFeatures as $feature)<p class="flex gap-3"><span class="font-bold text-ink" aria-hidden="true">✓</span>{{ $feature }}</p>@endforeach
                                <p class="flex gap-3"><span class="font-bold text-ink" aria-hidden="true">✓</span>{{ number_format($plan['limits']['api_requests_per_minute']) }} {{ __('API requests per minute') }}</p>
                            </div>
                        </details>
                        @php($planUrl = auth()->check() ? route('billing.index') : ($registrationOpen ? route('register') : route('access-request.create', ['plan' => $key])))
                        <x-signal.ui.button :href="$planUrl" variant="primary" class="w-full">{{ $registrationOpen ? ($key === 'free' ? __('Start free') : __('Start 14-day trial')) : __('Request access') }}</x-signal.ui.button>
                    </x-signal.ui.card>
                @endforeach
            </div>
            <div class="mx-auto mt-8 max-w-3xl space-y-2 text-center text-sm text-muted"><p>{{ __('Your provider bill stays separate. :app never marks up infrastructure costs.', ['app' => config('app.name')]) }}</p><p>{{ __('Unlimited plans are subject to a reasonable fair-use policy to prevent abusive or automated misuse.') }}</p></div>
            @unless($registrationOpen)<x-signal.ui.alert class="mx-auto mt-8 max-w-3xl text-center" tone="info"><p class="font-bold">{{ __(':app is currently onboarding customers by invitation.', ['app' => config('app.name')]) }}</p><p class="mt-1 text-sm">{{ __('Request access from any plan and tell us what you operate. No payment details are collected until you accept an invitation.') }}</p></x-signal.ui.alert>@endunless
        </div>
    </main>
</x-layouts.core>
