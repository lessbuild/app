@php
    $productUrl = config('platform.products.'.$productKey.'.url') ?: 'https://'.$productKey.'.buildpusher.com';
    $otherProducts = collect($products)->reject(fn (array $item, string $key): bool => $key === $productKey);
    $connections = $connections ?? collect(config('marketing.connections', []))
        ->filter(fn (array $connection): bool => in_array($productKey, [$connection['source'], $connection['target']], true))
        ->values()
        ->all();
@endphp

<x-signal.layouts.core
    :title="$product['name']"
    :description="$product['summary']"
    :canonical="route('core.marketing.product', $productKey)"
    :indexable="true"
    :livewire="false"
    :interactive-marketing="true"
>
    <a href="#main-content" class="ui-skip-link">{{ __('Skip to main content') }}</a>
    <x-signal.blocks.public-navigation :active-product="$productKey" />

    <main id="main-content" tabindex="-1" class="product-site min-w-0" data-product-site data-product-page="{{ $productKey }}">
        <section class="product-detail-hero border-b border-line bg-surface" aria-labelledby="product-heading">
            <div class="mx-auto grid max-w-screen-2xl items-center gap-8 px-5 py-12 sm:px-8 sm:py-16 lg:grid-cols-[.9fr_1.1fr] lg:gap-12 lg:py-20">
                <div class="min-w-0">
                    <x-signal.ui.link :href="route('core.entry').'#products'" layout="inline" size="inline" variant="muted" class="font-bold">
                        <span aria-hidden="true">←</span> {{ __('All Buildpusher apps') }}
                    </x-signal.ui.link>

                    <div class="mt-7 flex flex-wrap items-center gap-3">
                        <p class="product-accent-{{ $product['accent'] }} flex items-center gap-2 text-xs font-extrabold uppercase tracking-[0.15em]">
                            <x-signal.ui.icon :name="$product['icon']" class="size-4" />
                            {{ $product['eyebrow'] }}
                        </p>
                        <x-signal.ui.badge tone="neutral">{{ __('Product-specific access') }}</x-signal.ui.badge>
                    </div>
                    <h1 id="product-heading" class="mt-4 max-w-2xl text-4xl font-extrabold leading-[1.02] tracking-[-0.055em] text-ink sm:text-6xl">{{ $product['headline'] }}</h1>
                    <p class="mt-5 max-w-2xl text-lg leading-8 text-muted">{{ $product['summary'] }}</p>

                    <ul class="mt-6 grid gap-2 sm:grid-cols-1" aria-label="{{ __('Product highlights') }}">
                        @foreach ($product['card_features'] as $feature)
                            <li class="flex items-center gap-2 text-sm font-semibold text-ink">
                                <x-signal.ui.icon name="check" class="size-4 shrink-0 text-success" />
                                <span>{{ $feature }}</span>
                            </li>
                        @endforeach
                    </ul>

                    <div class="mt-8 flex flex-col gap-3 min-[440px]:flex-row">
                        <x-signal.ui.button :href="$productUrl" variant="primary" size="lg" class="justify-center">
                            {{ __('Open :name', ['name' => $product['name']]) }}
                            <x-signal.ui.icon name="arrow-up-right" class="size-4" />
                        </x-signal.ui.button>
                        <x-signal.ui.button :href="route('platform.login', ['return_to' => $productUrl])" variant="secondary" size="lg" class="justify-center">
                            {{ __('Sign in') }}
                        </x-signal.ui.button>
                    </div>

                    <p class="mt-4 text-xs leading-5 text-muted">{{ __('Use your shared Buildpusher account and project directory here. :name keeps its operational records and access rules in its own product workspace.', ['name' => $product['name']]) }}</p>
                </div>

                <x-signal.blocks.product-preview :product-key="$productKey" :product="$product" />
            </div>
        </section>

        @if (! empty($product['capabilities']))
            <section class="border-b border-line bg-surface" aria-label="{{ __('Product capabilities at a glance') }}">
                <div class="mx-auto flex max-w-screen-2xl flex-col gap-4 px-5 py-6 sm:px-8 lg:flex-row lg:items-center lg:justify-between">
                    <h2 class="text-xs font-bold uppercase tracking-widest text-muted">{{ __('At a glance') }}</h2>
                    <ul class="flex flex-wrap gap-2">
                        @foreach ($product['capabilities'] as $capability)
                            <li><x-signal.ui.badge tone="neutral" class="px-3 py-2 text-sm">{{ $capability }}</x-signal.ui.badge></li>
                        @endforeach
                    </ul>
                </div>
            </section>
        @endif

        <section class="bg-page py-14 sm:py-20" aria-labelledby="highlights-heading">
            <div class="mx-auto max-w-screen-2xl px-5 sm:px-8">
                <div class="mx-auto max-w-3xl text-center">
                    <p class="product-accent-{{ $product['accent'] }} text-xs font-extrabold uppercase tracking-[0.15em]">{{ $product['eyebrow'] }}</p>
                    <h2 id="highlights-heading" class="mt-3 text-3xl font-extrabold tracking-[-0.04em] text-ink sm:text-4xl">{{ $product['highlights_heading'] }}</h2>
                </div>

                <div class="mt-8 grid gap-4 md:grid-cols-2 {{ count($product['highlights']) >= 4 ? 'xl:grid-cols-4' : 'xl:grid-cols-3' }}">
                    @foreach ($product['highlights'] as $highlight)
                        <x-signal.ui.card as="article" class="p-5 sm:p-6">
                            <span class="product-icon-{{ $product['accent'] }} grid size-11 place-items-center rounded-xl">
                                <x-signal.ui.icon :name="$highlight['icon']" class="size-5" />
                            </span>
                            <h3 class="mt-5 text-lg font-extrabold text-ink">{{ $highlight['title'] }}</h3>
                            <p class="mt-2 text-sm leading-6 text-muted">{{ $highlight['description'] }}</p>
                        </x-signal.ui.card>
                    @endforeach
                </div>
            </div>
        </section>

        <section id="capabilities" class="border-y border-line bg-surface-muted/60 py-14 sm:py-20" aria-labelledby="capabilities-heading">
            <div class="mx-auto max-w-screen-2xl px-5 sm:px-8">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div class="max-w-3xl">
                        <p class="ui-eyebrow">{{ __('Inside :name', ['name' => $product['name']]) }}</p>
                        <h2 id="capabilities-heading" class="mt-3 text-3xl font-extrabold tracking-[-0.04em] text-ink sm:text-4xl">{{ __('What you can do') }}</h2>
                    </div>
                    <p class="max-w-2xl text-sm leading-6 text-muted">{{ __('A closer look at :name capabilities, grouped by the work they support.', ['name' => $product['name']]) }}</p>
                </div>

                <div class="mt-8 grid gap-5 lg:grid-cols-2">
                    @foreach ($product['groups'] as $group)
                        <x-signal.ui.card as="article" class="p-5 sm:p-7">
                            <p class="product-accent-{{ $product['accent'] }} text-xs font-extrabold uppercase tracking-[0.14em]">{{ $group['label'] }}</p>
                            <h3 class="mt-2 text-xl font-extrabold text-ink sm:text-2xl">{{ $group['title'] }}</h3>
                            <p class="mt-2 text-sm leading-6 text-muted">{{ $group['description'] }}</p>
                            <ul class="mt-5 grid gap-3 sm:grid-cols-2">
                                @foreach ($group['features'] as [$title, $description])
                                    <x-signal.blocks.product-feature :title="$title" :description="$description" />
                                @endforeach
                            </ul>
                        </x-signal.ui.card>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="bg-page py-14 sm:py-20" aria-labelledby="workflows-heading">
            <div class="mx-auto grid max-w-screen-2xl gap-10 px-5 sm:px-8 lg:grid-cols-2">
                <div>
                    <p class="ui-eyebrow">{{ __('A practical path through :name', ['name' => $product['name']]) }}</p>
                    <h2 id="workflows-heading" class="mt-3 text-3xl font-extrabold tracking-tight text-ink">{{ $product['workflows_heading'] }}</h2>
                    <p class="mt-3 max-w-xl text-sm leading-7 text-muted">{{ $product['workflows_intro'] }}</p>
                    <ol class="mt-6 grid gap-3">
                        @foreach ($product['workflows'] as [$title, $description])
                            <li>
                                <x-signal.ui.card as="article" class="flex gap-4 p-4">
                                    <span class="product-icon-{{ $product['accent'] }} grid size-8 shrink-0 place-items-center rounded-full text-xs font-extrabold">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                                    <div>
                                        <h3 class="font-bold text-ink">{{ $title }}</h3>
                                        <p class="mt-1 text-sm leading-6 text-muted">{{ $description }}</p>
                                    </div>
                                </x-signal.ui.card>
                            </li>
                        @endforeach
                    </ol>
                </div>

                <x-signal.ui.card as="section" class="ui-emphasis relative overflow-hidden border-0 p-6 sm:p-8" aria-labelledby="guardrails-heading">
                    <p class="text-xs font-extrabold uppercase tracking-[0.16em] text-brand-300">{{ __('Safety and accountability') }}</p>
                    <h2 id="guardrails-heading" class="mt-3 text-2xl font-extrabold tracking-tight text-emphasis-ink">{{ $product['guardrails_title'] }}</h2>
                    <p class="ui-emphasis-muted mt-3 leading-7">{{ $product['guardrails_description'] }}</p>
                    <ul class="mt-6 grid gap-3 sm:grid-cols-2">
                        @foreach ($product['guardrails'] as $guardrail)
                            <li class="flex items-start gap-2 text-sm font-semibold text-emphasis-ink">
                                <x-signal.ui.icon name="check" class="mt-0.5 size-4 shrink-0" />
                                <span>{{ $guardrail }}</span>
                            </li>
                        @endforeach
                    </ul>
                </x-signal.ui.card>
            </div>
        </section>

        <section class="border-y border-line bg-surface-muted/60 py-14 sm:py-20" aria-labelledby="project-context-heading">
            <div class="mx-auto max-w-screen-2xl px-5 sm:px-8">
                <div class="mx-auto max-w-3xl text-center">
                    <p class="ui-eyebrow">{{ __('Connected by project') }}</p>
                    <h2 id="project-context-heading" class="mt-3 text-3xl font-extrabold tracking-tight text-ink sm:text-4xl">{{ __('Shared context across Buildpusher.') }}</h2>
                    <p class="mt-4 text-base leading-7 text-muted">{{ __('Open a project from your shared workspace, connect supported resources to it, and follow authorized product activity from the project dashboard. Each app keeps its operational records and entitlement checks in its own product database.') }}</p>
                </div>

                @if (! empty($connections))
                    <div class="mt-8 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($connections as $connection)
                            <x-signal.blocks.product-connection :connection="$connection" :products="$products" />
                        @endforeach
                    </div>
                    <p class="mt-4 text-center text-xs leading-5 text-muted">{{ __('These workflows require explicit project connections, current product access, and any applicable plan entitlement.') }}</p>
                @endif

                <dl class="mt-8 grid gap-4 md:grid-cols-3">
                    <x-signal.ui.card as="div" class="p-5">
                        <dt class="font-extrabold text-ink">{{ __('One account') }}</dt>
                        <dd class="mt-2 text-sm leading-6 text-muted">{{ __('Use the same Buildpusher sign-in to move between product subdomains.') }}</dd>
                    </x-signal.ui.card>
                    <x-signal.ui.card as="div" class="p-5">
                        <dt class="font-extrabold text-ink">{{ __('A shared project directory') }}</dt>
                        <dd class="mt-2 text-sm leading-6 text-muted">{{ __('Carry project identity and team context between the apps you enable.') }}</dd>
                    </x-signal.ui.card>
                    <x-signal.ui.card as="div" class="p-5">
                    <dt class="font-extrabold text-ink">{{ __('App-specific access and plan state') }}</dt>
                    <dd class="mt-2 text-sm leading-6 text-muted">{{ __(':name checks its own workspace access and entitlements. A plan or access change in another Buildpusher app does not automatically grant access here; billing options depend on the product.', ['name' => $product['name']]) }}</dd>
                    </x-signal.ui.card>
                </dl>
            </div>
        </section>

        <section class="bg-page py-14 sm:py-20" aria-labelledby="related-products-heading">
            <div class="mx-auto max-w-screen-2xl px-5 sm:px-8">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="ui-eyebrow">{{ __('More from Buildpusher') }}</p>
                        <h2 id="related-products-heading" class="mt-3 text-3xl font-extrabold tracking-tight text-ink">{{ __('Choose another view of the same project.') }}</h2>
                    </div>
                    <p class="max-w-xl text-sm leading-6 text-muted">{{ __('Use the products your team needs, each with its own focused capabilities and workspace access rules.') }}</p>
                </div>

                <div class="mt-7 grid gap-4 md:grid-cols-2">
                    @foreach ($otherProducts as $otherKey => $otherProduct)
                        <x-signal.blocks.product-card
                            :name="$otherProduct['name']"
                            :accent="$otherProduct['accent']"
                            :icon="$otherProduct['icon']"
                            :eyebrow="$otherProduct['eyebrow']"
                            :summary="$otherProduct['card_summary']"
                            :features="$otherProduct['card_features']"
                            :href="route('core.marketing.product', $otherKey)"
                        />
                    @endforeach
                </div>
            </div>
        </section>

        @if (! empty($product['questions']))
            <section class="bg-page py-14 sm:py-20" aria-labelledby="product-questions-heading">
                <div class="mx-auto grid max-w-screen-2xl gap-8 px-5 sm:px-8 lg:grid-cols-[.7fr_1.3fr]">
                    <div>
                        <p class="ui-eyebrow">{{ __('Good to know') }}</p>
                        <h2 id="product-questions-heading" class="mt-3 text-3xl font-extrabold tracking-tight text-ink">{{ __('Straight answers about :name.', ['name' => $product['name']]) }}</h2>
                    </div>
                    <x-signal.blocks.faq-list :items="$product['questions']" aria-labelledby="product-questions-heading" />
                </div>
            </section>
        @endif

        <section class="border-t border-line bg-surface py-14 sm:py-20">
            <div class="mx-auto max-w-screen-2xl px-5 sm:px-8">
                <x-signal.blocks.cta
                    :eyebrow="__('Part of Buildpusher')"
                    :title="__('Continue with :name.', ['name' => $product['name']])"
                    :copy="__('Open the :name dashboard with the same account and project directory your team uses across Buildpusher.', ['name' => $product['name']])"
                    :href="$productUrl"
                    :label="__('Open :name', ['name' => $product['name']])"
                />
            </div>
        </section>
    </main>

    <x-signal.site-footer
        :description="__('Explore the Buildpusher apps and manage your team and projects in one connected workspace.')"
        :explore-links="[
            ['label' => __('All products'), 'href' => route('core.entry').'#products'],
            ['label' => __('Deployer'), 'href' => route('core.marketing.product', 'deployer')],
            ['label' => __('Monitor'), 'href' => route('core.marketing.product', 'monitor')],
            ['label' => __('Analytics'), 'href' => route('core.marketing.product', 'analytics')],
            ['label' => __('Pricing'), 'href' => route('core.pricing')],
            ['label' => __('Help and API docs'), 'href' => route('core.help')],
            ['label' => __('Platform status'), 'href' => route('core.status')],
        ]"
        :closing-eyebrow="__('Your :name workspace', ['name' => $product['name']])"
        :closing-copy="__('Sign in to continue to the product dashboard. Its access rules and operational data remain product-specific.')"
        :action-href="$productUrl"
        :action-label="__('Open :name', ['name' => $product['name']])"
    />
</x-signal.layouts.core>
