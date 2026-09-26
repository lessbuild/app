<x-signal.layouts.core
    :title="__('Pricing')"
    :description="__('Compare Deployer and Monitor plans, with each app billed separately for its workspace.')"
    :canonical="route('core.pricing')"
    :indexable="true"
    :livewire="false"
>
    <a href="#main-content" class="ui-skip-link">{{ __('Skip to main content') }}</a>
    <x-signal.blocks.public-navigation />

    <main id="main-content" tabindex="-1" class="mx-auto max-w-screen-2xl px-5 py-12 sm:px-8 sm:py-16">
        <x-signal.ui.page-header
            :eyebrow="__('Separate plans for each app')"
            :title="__('Choose the tools your team needs.')"
            :description="__('Each workspace has an independent plan for Deployer, Monitor, and Analytics. Changing one app subscription does not change another.')"
        >
            <x-slot:actions>
                <x-signal.ui.badge tone="info">{{ __('Billed per app and workspace') }}</x-signal.ui.badge>
            </x-slot:actions>
        </x-signal.ui.page-header>

        <div class="mt-12" x-data="{ activeProduct: 'deployer' }" data-pricing-tabs>
            <x-signal.ui.tablist
                label="{{ __('Pricing by app') }}"
                class="w-full sm:w-fit"
                @keydown="if (['ArrowRight', 'ArrowLeft', 'Home', 'End'].includes($event.key)) { const tabs = [...$el.querySelectorAll('[role=tab]')]; let nextIndex = tabs.indexOf($event.target); if ($event.key === 'Home') nextIndex = 0; else if ($event.key === 'End') nextIndex = tabs.length - 1; else nextIndex = (nextIndex + ($event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length; activeProduct = tabs[nextIndex].dataset.productTab; tabs[nextIndex].focus(); }"
            >
                <x-signal.ui.tab
                    id="pricing-tab-deployer"
                    data-product-tab="deployer"
                    aria-controls="pricing-panel-deployer"
                    :selected="true"
                    x-bind:aria-selected="activeProduct === 'deployer' ? 'true' : 'false'"
                    x-bind:tabindex="activeProduct === 'deployer' ? '0' : '-1'"
                    @click="activeProduct = 'deployer'"
                >{{ __('Deployer') }}</x-signal.ui.tab>
                <x-signal.ui.tab
                    id="pricing-tab-monitor"
                    data-product-tab="monitor"
                    aria-controls="pricing-panel-monitor"
                    x-bind:aria-selected="activeProduct === 'monitor' ? 'true' : 'false'"
                    x-bind:tabindex="activeProduct === 'monitor' ? '0' : '-1'"
                    @click="activeProduct = 'monitor'"
                >{{ __('Monitor') }}</x-signal.ui.tab>
                <x-signal.ui.tab
                    id="pricing-tab-analytics"
                    data-product-tab="analytics"
                    aria-controls="pricing-panel-analytics"
                    x-bind:aria-selected="activeProduct === 'analytics' ? 'true' : 'false'"
                    x-bind:tabindex="activeProduct === 'analytics' ? '0' : '-1'"
                    @click="activeProduct = 'analytics'"
                >{{ __('Analytics') }}</x-signal.ui.tab>
            </x-signal.ui.tablist>

        <x-signal.ui.tab-panel id="pricing-panel-deployer" aria-labelledby="pricing-tab-deployer" x-show="activeProduct === 'deployer'" x-data="{ interval: 'yearly' }" data-product-pricing="deployer">
            <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="ui-eyebrow">{{ __('Build and ship') }}</p>
                    <h2 id="deployer-pricing-title" class="mt-2 text-2xl font-extrabold tracking-tight text-ink sm:text-3xl">{{ __('Deployer plans') }}</h2>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">{{ __('Provision infrastructure, release from Git, and operate production. Provider charges remain separate.') }}</p>
                </div>
                <div class="inline-flex w-fit rounded-control border border-line bg-surface-muted p-1" role="group" aria-label="{{ __('Deployer billing interval') }}">
                    <x-signal.ui.button variant="stateful" type="button" @click="interval = 'monthly'" x-bind:class="interval === 'monthly' ? 'ui-btn-primary' : 'ui-btn-quiet'" x-bind:aria-pressed="(interval === 'monthly').toString()" class="ui-btn ui-btn-sm">{{ __('Monthly') }}</x-signal.ui.button>
                    <x-signal.ui.button variant="stateful" type="button" @click="interval = 'yearly'" x-bind:class="interval === 'yearly' ? 'ui-btn-primary' : 'ui-btn-quiet'" x-bind:aria-pressed="(interval === 'yearly').toString()" class="ui-btn ui-btn-sm">{{ __('Yearly · save 2 months') }}</x-signal.ui.button>
                </div>
            </div>

            <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($deployerPlans as $key => $plan)
                    @php($isFeatured = $key === 'pro')
                    <x-signal.ui.card data-pricing-plan="deployer-{{ $key }}" @class(['relative flex h-full flex-col p-6 sm:p-7', 'border-2 ring-2' => $isFeatured]) @style(['border-color: var(--ui-primary); --tw-ring-color: var(--ui-primary)' => $isFeatured])>
                        @if ($isFeatured)
                            <x-signal.ui.badge class="absolute -top-3 left-6" tone="accent">{{ __('Most popular') }}</x-signal.ui.badge>
                        @endif
                        <h3 class="text-xl font-extrabold text-ink">{{ $plan['name'] }}</h3>
                        <p class="mt-2 min-h-20 text-sm leading-6 text-muted">{{ $plan['description'] }}</p>
                        <p class="mt-6 flex items-baseline gap-1 text-ink">
                            @if ((float) $plan['price'] > 0)
                                <span class="text-4xl font-extrabold" x-text="interval === 'yearly' ? {{ Illuminate\Support\Js::from('$'.number_format((float) ($plan['yearly_price'] ?? $plan['price']), 0)) }} : {{ Illuminate\Support\Js::from('$'.number_format((float) $plan['price'], 0)) }}">{{ '$'.number_format((float) ($plan['yearly_price'] ?? $plan['price']), 0) }}</span>
                                <span class="text-sm text-muted" x-text="interval === 'yearly' ? '/year' : '/month'">{{ __(' /year') }}</span>
                            @else
                                <span class="text-4xl font-extrabold">$0</span>
                                <span class="text-sm text-muted">{{ __('forever') }}</span>
                            @endif
                        </p>
                        @if ((float) $plan['price'] > 0)
                            <p x-show="interval === 'yearly'" class="mt-1 text-xs font-semibold text-muted">{{ __('Equivalent to $:price/month', ['price' => number_format(((float) ($plan['yearly_price'] ?? $plan['price'] * 12)) / 12, 2)]) }}</p>
                        @endif

                        <ul class="my-6 grid flex-1 gap-3 text-sm leading-6 text-muted">
                            @foreach ($plan['features'] as $feature)
                                <li class="flex gap-3"><span class="font-bold text-ink" aria-hidden="true">✓</span><span>{{ $feature }}</span></li>
                            @endforeach
                        </ul>

                        <x-signal.ui.panel as="dl" aria-label="{{ __('Plan limits') }}" class="grid grid-cols-2 gap-x-3 gap-y-2 bg-surface-muted p-4 text-xs shadow-none">
                            @foreach (['servers' => __('Servers'), 'websites' => __('Websites'), 'members' => __('Team members'), 'preview_deployments' => __('Preview deployments'), 'api_requests_per_minute' => __('API requests / minute')] as $limitKey => $label)
                                @php($limit = data_get($plan, 'limits.'.$limitKey))
                                <dt class="text-muted">{{ $label }}</dt>
                                <dd class="text-right font-bold text-ink">{{ $limit === null ? __('Unlimited') : number_format((int) $limit) }}</dd>
                            @endforeach
                        </x-signal.ui.panel>
                        <x-signal.ui.button :href="auth('platform')->check() ? route('core.home') : route('platform.register')" variant="primary" class="mt-5 w-full">
                            {{ auth('platform')->check() ? __('Open workspace') : __('Create a workspace') }}
                        </x-signal.ui.button>
                    </x-signal.ui.card>
                @endforeach
            </div>
        </x-signal.ui.tab-panel>

        <x-signal.ui.tab-panel id="pricing-panel-monitor" aria-labelledby="pricing-tab-monitor" x-show="activeProduct === 'monitor'" data-product-pricing="monitor">
            <div class="mb-6">
                <p class="ui-eyebrow">{{ __('Understand production') }}</p>
                <h2 id="monitor-pricing-title" class="mt-2 text-2xl font-extrabold tracking-tight text-ink sm:text-3xl">{{ __('Monitor plans') }}</h2>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">{{ __('Observe service health and application telemetry with an independent Monitor subscription.') }}</p>
            </div>

            <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-4">
                @foreach ($monitorPlans as $key => $plan)
                    @php($isFeatured = $key === 'pro')
                    <x-signal.ui.card data-pricing-plan="monitor-{{ $key }}" @class(['relative flex h-full flex-col p-6', 'border-2 ring-2' => $isFeatured]) @style(['border-color: var(--ui-primary); --tw-ring-color: var(--ui-primary)' => $isFeatured])>
                        @if ($isFeatured)
                            <x-signal.ui.badge class="absolute -top-3 left-5" tone="accent">{{ __('Most popular') }}</x-signal.ui.badge>
                        @endif
                        <h3 class="text-xl font-extrabold text-ink">{{ $plan['name'] }}</h3>
                        <p class="mt-2 min-h-20 text-sm leading-6 text-muted">{{ $plan['description'] }}</p>
                        <p class="mt-6 flex items-baseline gap-1 text-ink">
                            <span class="text-4xl font-extrabold">{{ '$'.number_format((float) $plan['price'], 0) }}</span>
                            <span class="text-sm text-muted">{{ (float) $plan['price'] > 0 ? __('/month') : __('forever') }}</span>
                        </p>
                        <ul class="my-6 grid flex-1 gap-3 text-sm leading-6 text-muted">
                            @foreach ($plan['features'] as $feature)
                                <li class="flex gap-3"><span class="font-bold text-ink" aria-hidden="true">✓</span><span>{{ $feature }}</span></li>
                            @endforeach
                        </ul>
                        <x-signal.ui.panel as="dl" aria-label="{{ __('Plan limits') }}" class="grid grid-cols-2 gap-x-3 gap-y-2 bg-surface-muted p-4 text-xs shadow-none">
                            @foreach (['event_limit' => __('Events / month'), 'retention_days' => __('Retention / days'), 'apps' => __('Applications'), 'seats' => __('Seats'), 'dashboards' => __('Saved dashboards')] as $limitKey => $label)
                                @php($limit = data_get($plan, $limitKey))
                                <dt class="text-muted">{{ $label }}</dt>
                                <dd class="text-right font-bold text-ink">{{ is_numeric($limit) ? number_format((int) $limit) : $limit }}</dd>
                            @endforeach
                        </x-signal.ui.panel>
                        <x-signal.ui.button :href="auth('platform')->check() ? route('core.home') : route('platform.register')" variant="secondary" class="mt-5 w-full">
                            {{ auth('platform')->check() ? __('Open workspace') : __('Create a workspace') }}
                        </x-signal.ui.button>
                    </x-signal.ui.card>
                @endforeach
            </div>
        </x-signal.ui.tab-panel>

        <x-signal.ui.tab-panel id="pricing-panel-analytics" aria-labelledby="pricing-tab-analytics" x-show="activeProduct === 'analytics'" data-product-pricing="analytics">
            <x-signal.ui.card class="grid min-h-64 gap-6 p-6 sm:p-8 lg:grid-cols-[1fr_auto] lg:items-center">
                <div>
                    <p class="ui-eyebrow">{{ __('Measure and learn') }}</p>
                    <h2 id="analytics-pricing-title" class="mt-2 text-2xl font-extrabold tracking-tight text-ink">{{ __('Analytics plans') }}</h2>
                    <p class="mt-3 max-w-3xl text-sm leading-6 text-muted">{{ __('Analytics does not yet have a published paid plan catalog. Existing Analytics access stays separate from Deployer and Monitor; no Analytics price or tier is assumed here.') }}</p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <x-signal.ui.button :href="route('core.marketing.product', 'analytics')" variant="secondary">{{ __('Explore Analytics') }}</x-signal.ui.button>
                    <x-signal.ui.button :href="'mailto:'.config('legal.contact_email')" variant="quiet">{{ __('Ask about Analytics plans') }}</x-signal.ui.button>
                </div>
            </x-signal.ui.card>
        </x-signal.ui.tab-panel>
        </div>

        <x-signal.ui.alert tone="info" class="mt-10">
            {{ __('Infrastructure and provider charges are separate from app subscriptions. App subscriptions, included usage, and cancellation are managed independently for each workspace.') }}
        </x-signal.ui.alert>

        <footer class="mt-8 flex flex-wrap gap-3">
            <x-signal.ui.button :href="route('core.access-request.create')" variant="secondary">{{ __('Request Deployer access') }}</x-signal.ui.button>
            <x-signal.ui.button :href="route('core.help')" variant="secondary">{{ __('Help and API docs') }}</x-signal.ui.button>
            <x-signal.ui.button :href="route('core.status')" variant="quiet">{{ __('Service status') }}</x-signal.ui.button>
        </footer>
    </main>
</x-signal.layouts.core>
