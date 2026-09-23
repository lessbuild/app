<x-signal.layouts.core
    :title="__('One workspace for your software operations')"
    :description="__('Bring Buildpusher Deployer, Monitor, and Analytics together with shared accounts and projects, connected workflows, and separate plans for each app.')"
    :canonical="route('core.entry')"
    :indexable="true"
    :livewire="false"
>
    <a href="#main-content" class="ui-skip-link">{{ __('Skip to main content') }}</a>
    <x-signal.blocks.public-navigation />

    <main id="main-content" tabindex="-1">
        <section class="relative overflow-hidden border-b border-line bg-surface">
            <div class="surface-grid absolute inset-0 opacity-40" aria-hidden="true"></div>
            <div class="relative mx-auto grid max-w-screen-2xl items-center gap-10 px-5 py-14 sm:px-8 sm:py-20 lg:grid-cols-[1fr_.9fr] lg:gap-16 lg:py-24">
                <div>
                    <x-signal.ui.badge tone="accent">{{ __('One platform. Three focused apps.') }}</x-signal.ui.badge>
                    <h1 class="mt-6 max-w-3xl text-4xl font-extrabold leading-[1.04] tracking-[-0.05em] text-ink sm:text-6xl">{{ __('One workspace for the work behind your software.') }}</h1>
                    <p class="mt-5 max-w-2xl text-lg leading-8 text-muted">{{ __('Buildpusher brings deployments, production monitoring, and website analytics into a connected workspace, so your team can move between the tools without losing project context.') }}</p>

                    <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                        <x-signal.ui.button href="#products" variant="primary" class="ui-btn-lg justify-center">{{ __('Explore the apps') }}</x-signal.ui.button>
                        <x-signal.ui.button :href="auth('platform')->check() ? route('core.home') : route('platform.login')" variant="secondary" class="ui-btn-lg justify-center">{{ auth('platform')->check() ? __('Open your workspace') : __('Sign in') }}</x-signal.ui.button>
                    </div>

                    <ul class="mt-7 flex flex-wrap gap-x-6 gap-y-3 text-sm font-semibold text-muted">
                        @foreach ([__('One shared account'), __('Projects carry across apps'), __('Independent app plans')] as $promise)
                            <li class="flex items-center gap-2"><span class="grid size-5 place-items-center rounded-full bg-emphasis text-[0.65rem] font-black text-emphasis-ink" aria-hidden="true">✓</span>{{ $promise }}</li>
                        @endforeach
                    </ul>
                </div>

                <x-signal.ui.card class="relative overflow-hidden p-4 shadow-panel sm:p-6" aria-label="{{ __('Illustration of a connected project') }}">
                    <div class="flex items-start justify-between gap-4 border-b border-line pb-4">
                        <div>
                            <p class="ui-eyebrow">{{ __('Shared project') }}</p>
                            <h2 class="mt-1 text-xl font-extrabold text-ink">{{ __('Storefront') }}</h2>
                        </div>
                        <x-signal.ui.badge>{{ __('Illustrative') }}</x-signal.ui.badge>
                    </div>
                    <p class="mt-4 text-sm leading-6 text-muted">{{ __('One project gives your team a common place to connect product resources and follow work across apps.') }}</p>

                    <div class="mt-5 grid gap-3">
                        @foreach ([
                            ['Deployer', __('Release and infrastructure')],
                            ['Monitor', __('Health and application signals')],
                            ['Analytics', __('Traffic and conversion goals')],
                        ] as [$name, $context])
                            <div class="flex items-center gap-3 rounded-control border border-line bg-surface-muted p-3">
                                <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-emphasis text-xs font-black text-emphasis-ink" aria-hidden="true">{{ mb_substr($name, 0, 1) }}</span>
                                <div class="min-w-0"><p class="text-sm font-bold text-ink">{{ $name }}</p><p class="mt-0.5 truncate text-xs text-muted">{{ $context }}</p></div>
                                <span class="ml-auto text-muted" aria-hidden="true">↗</span>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-5 flex items-center gap-2 border-t border-line pt-4 text-xs leading-5 text-muted">
                        <span class="size-2 shrink-0 rounded-full bg-success" aria-hidden="true"></span>
                        <span>{{ __('Example only · connections and activity depend on your project setup.') }}</span>
                    </div>
                </x-signal.ui.card>
            </div>
        </section>

        <section id="products" class="scroll-mt-20 bg-page py-16 sm:py-24" aria-labelledby="products-heading">
            <div class="mx-auto max-w-screen-2xl px-5 sm:px-8">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div class="max-w-2xl">
                        <p class="ui-eyebrow">{{ __('The Buildpusher apps') }}</p>
                        <h2 id="products-heading" class="mt-3 text-3xl font-extrabold tracking-tight text-ink sm:text-4xl">{{ __('Choose the tool for the work in front of you.') }}</h2>
                    </div>
                    <p class="max-w-xl leading-7 text-muted">{{ __('Each app has its own focused capabilities and subscription. Your team, account, and project directory stay connected across the platform.') }}</p>
                </div>

                <div class="mt-9 grid gap-4 lg:grid-cols-3">
                    @foreach ($products as $slug => $product)
                        <x-signal.blocks.product-card
                            :name="$product['name']"
                            :eyebrow="$product['eyebrow']"
                            :summary="$product['card_summary']"
                            :features="$product['card_features']"
                            :href="route('core.marketing.product', $slug)"
                        />
                    @endforeach
                </div>
            </div>
        </section>

        <section class="border-y border-line bg-surface-muted/60 py-16 sm:py-20" aria-labelledby="connected-work-heading">
            <div class="mx-auto grid max-w-screen-2xl gap-10 px-5 sm:px-8 lg:grid-cols-[.8fr_1.2fr] lg:items-center">
                <div>
                    <p class="ui-eyebrow">{{ __('Connected by project') }}</p>
                    <h2 id="connected-work-heading" class="mt-3 text-3xl font-extrabold tracking-tight text-ink sm:text-4xl">{{ __('Keep the context. Use the right app.') }}</h2>
                    <p class="mt-4 max-w-xl leading-7 text-muted">{{ __('Projects are shared across Buildpusher. Connect compatible resources between apps to relate a release to service health or a website to its analytics, while each product keeps its own operational data and billing.') }}</p>
                    <x-signal.ui.button :href="route('core.marketing.product', 'deployer')" variant="secondary" class="mt-6">{{ __('See how the apps work') }}</x-signal.ui.button>
                </div>

                <div class="grid gap-3 sm:grid-cols-3">
                    @foreach ([
                        ['01', __('Create a project'), __('Give the work a shared home for your team.')],
                        ['02', __('Connect products'), __('Link compatible app resources to the project.')],
                        ['03', __('Follow the work'), __('Move between tools with project context intact.')],
                    ] as [$step, $title, $description])
                        <x-signal.ui.card class="p-5">
                            <x-signal.ui.badge tone="accent">{{ $step }}</x-signal.ui.badge>
                            <h3 class="mt-5 font-extrabold text-ink">{{ $title }}</h3>
                            <p class="mt-2 text-sm leading-6 text-muted">{{ $description }}</p>
                        </x-signal.ui.card>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="bg-page py-16 sm:py-24" aria-labelledby="platform-model-heading">
            <div class="mx-auto max-w-5xl px-5 sm:px-8">
                <div class="text-center">
                    <p class="ui-eyebrow">{{ __('A clear platform model') }}</p>
                    <h2 id="platform-model-heading" class="mt-3 text-3xl font-extrabold tracking-tight text-ink sm:text-4xl">{{ __('Shared where it helps. Separate where it matters.') }}</h2>
                </div>
                <dl class="mt-9 grid gap-4 md:grid-cols-3">
                    <x-signal.ui.card class="p-5 sm:p-6">
                        <dt class="text-sm font-extrabold text-ink">{{ __('Account and sign-in') }}</dt>
                        <dd class="mt-2 text-sm leading-6 text-muted">{{ __('Use one Buildpusher account and move between the apps with shared authentication.') }}</dd>
                    </x-signal.ui.card>
                    <x-signal.ui.card class="p-5 sm:p-6">
                        <dt class="text-sm font-extrabold text-ink">{{ __('Team and projects') }}</dt>
                        <dd class="mt-2 text-sm leading-6 text-muted">{{ __('Manage your team and project directory once, then connect each project to the products you use.') }}</dd>
                    </x-signal.ui.card>
                    <x-signal.ui.card class="p-5 sm:p-6">
                        <dt class="text-sm font-extrabold text-ink">{{ __('Plans and app data') }}</dt>
                        <dd class="mt-2 text-sm leading-6 text-muted">{{ __('Choose and manage a separate subscription for Deployer, Monitor, and Analytics. Each app keeps its own operational database.') }}</dd>
                    </x-signal.ui.card>
                </dl>
            </div>
        </section>

        <section class="border-t border-line bg-surface py-14 sm:py-20">
            <div class="mx-auto max-w-screen-2xl px-5 sm:px-8">
                <x-signal.blocks.cta
                    :eyebrow="__('Start with your projects')"
                    :title="__('Bring your team into one Buildpusher workspace.')"
                    :copy="__('Choose the apps you need and keep each product plan under your workspace.')"
                    :href="auth('platform')->check() ? route('core.home') : route('platform.register')"
                    :label="auth('platform')->check() ? __('Open your workspace') : __('Create a workspace')"
                />
            </div>
        </section>
    </main>

    <x-signal.site-footer
        :description="__('A connected workspace for deploying, monitoring, and understanding your software.')"
        :explore-links="[
            ['label' => __('Deployer'), 'href' => route('core.marketing.product', 'deployer')],
            ['label' => __('Monitor'), 'href' => route('core.marketing.product', 'monitor')],
            ['label' => __('Analytics'), 'href' => route('core.marketing.product', 'analytics')],
            ['label' => __('Sign in'), 'href' => route('platform.login')],
        ]"
        :closing-eyebrow="__('Your workspace, your tools')"
        :closing-copy="__('Sign in to manage team access, projects, product connections, and each app subscription.')"
        :action-href="auth('platform')->check() ? route('core.home') : route('platform.login')"
        :action-label="auth('platform')->check() ? __('Open your workspace') : __('Sign in to Buildpusher')"
    />
</x-signal.layouts.core>
