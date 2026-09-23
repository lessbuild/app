@php
    $productUrl = config('platform.products.'.$productKey.'.url') ?: 'https://'.$productKey.'.buildpusher.com';
    $featureCount = collect($product['groups'])->sum(fn (array $group): int => count($group['features']));
@endphp

<x-signal.layouts.core
    :title="$product['name'].' · Buildpusher'"
    :description="$product['summary']"
    :canonical="route('core.marketing.product', $productKey)"
    :indexable="true"
    :livewire="false"
>
    <a href="#main-content" class="ui-skip-link">{{ __('Skip to main content') }}</a>
    <x-signal.blocks.public-navigation :active-product="$productKey" />

    <main id="main-content" tabindex="-1">
        <section class="border-b border-line bg-surface">
            <div class="mx-auto max-w-screen-2xl px-5 py-14 sm:px-8 sm:py-20">
                <a href="{{ route('core.entry') }}#products" class="inline-flex items-center gap-2 text-sm font-bold text-muted transition hover:text-ink"><span aria-hidden="true">←</span>{{ __('All Buildpusher apps') }}</a>
                <div class="mt-8 grid gap-8 lg:grid-cols-[1fr_auto] lg:items-end">
                    <div class="max-w-4xl">
                        <x-signal.ui.badge tone="accent">{{ $product['eyebrow'] }} · {{ __('Separate app plan') }}</x-signal.ui.badge>
                        <h1 class="mt-5 text-4xl font-extrabold leading-[1.04] tracking-[-0.05em] text-ink sm:text-6xl">{{ $product['headline'] }}</h1>
                        <p class="mt-5 max-w-3xl text-lg leading-8 text-muted">{{ $product['summary'] }}</p>
                        <p class="mt-4 text-sm leading-6 text-muted">{{ __('Use your shared Buildpusher account and projects here. This app retains its own operational data and subscription.') }}</p>
                    </div>
                    <div class="flex flex-col gap-3 sm:flex-row lg:flex-col">
                        <x-signal.ui.button :href="$productUrl" variant="primary" class="justify-center">{{ __('Open :name', ['name' => $product['name']]) }} <span aria-hidden="true">↗</span></x-signal.ui.button>
                        <x-signal.ui.button :href="route('platform.login', ['return_to' => $productUrl])" variant="secondary" class="justify-center">{{ __('Sign in') }}</x-signal.ui.button>
                    </div>
                </div>
                <dl class="mt-10 grid max-w-3xl grid-cols-2 gap-3 sm:grid-cols-3">
                    <x-signal.ui.card class="p-4"><x-signal.ui.stat :label="__('Feature groups')" :value="count($product['groups'])" :description="__('Focused ways to work')" /></x-signal.ui.card>
                    <x-signal.ui.card class="p-4"><x-signal.ui.stat :label="__('Capabilities')" :value="$featureCount" :description="__('Across this product')" /></x-signal.ui.card>
                    <x-signal.ui.card class="col-span-2 p-4 sm:col-span-1"><x-signal.ui.stat :label="__('Workspace access')" :value="__('Shared')" :description="__('Account and project context')" /></x-signal.ui.card>
                </dl>
            </div>
        </section>

        <section class="bg-page py-14 sm:py-20" aria-labelledby="capabilities-heading">
            <div class="mx-auto max-w-screen-2xl px-5 sm:px-8">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="ui-eyebrow">{{ __('Inside :name', ['name' => $product['name']]) }}</p>
                        <h2 id="capabilities-heading" class="mt-3 text-3xl font-extrabold tracking-tight text-ink sm:text-4xl">{{ __('What you can do') }}</h2>
                    </div>
                    <p class="max-w-2xl text-sm leading-6 text-muted">{{ __('These capabilities stay available in the :name app. The app roots open its dashboard; these product pages live on Buildpusher.', ['name' => $product['name']]) }}</p>
                </div>

                <div class="mt-8 grid gap-5 lg:grid-cols-2">
                    @foreach ($product['groups'] as $group)
                        <x-signal.ui.card class="p-5 sm:p-7">
                            <p class="ui-eyebrow">{{ $group['label'] }}</p>
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

        @if (! empty($product['providers']))
            <section class="border-y border-line bg-surface-muted" aria-label="{{ __('Supported providers') }}">
                <div class="mx-auto flex max-w-screen-2xl flex-col gap-4 px-5 py-6 sm:px-8 lg:flex-row lg:items-center lg:justify-between">
                    <h2 class="text-xs font-bold uppercase tracking-widest text-muted">{{ __('Works with providers you already use') }}</h2>
                    <ul class="flex flex-wrap gap-2">
                        @foreach ($product['providers'] as $provider)
                            <li class="rounded-control border border-line bg-surface px-3 py-2 text-sm font-bold text-ink">{{ $provider }}</li>
                        @endforeach
                    </ul>
                </div>
            </section>
        @endif

        @if (! empty($product['tour']))
            <section class="bg-surface-muted/60 py-14 sm:py-20" aria-labelledby="product-tour-heading">
                <div class="mx-auto max-w-screen-2xl px-5 sm:px-8">
                    <div class="max-w-3xl">
                        <p class="ui-eyebrow">{{ __('Inside Deployer') }}</p>
                        <h2 id="product-tour-heading" class="mt-3 text-3xl font-extrabold tracking-tight text-ink sm:text-4xl">{{ __('A clear view of the operational work.') }}</h2>
                        <p class="mt-3 leading-7 text-muted">{{ __('These representative previews show where common infrastructure and release tasks live. All values are examples, not live workspace data.') }}</p>
                    </div>
                    <div class="mt-8 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($product['tour'] as $area)
                            <x-signal.ui.card class="p-5">
                                <div class="flex items-center justify-between gap-3">
                                    <p class="ui-eyebrow">{{ $area['label'] }}</p>
                                    <x-signal.ui.badge>{{ __('Example') }}</x-signal.ui.badge>
                                </div>
                                <h3 class="mt-2 font-extrabold text-ink">{{ $area['title'] }}</h3>
                                <p class="mt-2 min-h-12 text-sm leading-6 text-muted">{{ $area['description'] }}</p>
                                <div class="mt-4 rounded-control border border-line bg-surface-muted p-3">
                                    <p class="text-xs font-bold text-muted">{{ $area['window'] }}</p>
                                    <dl class="mt-3 grid grid-cols-3 gap-2">
                                        @foreach ($area['metrics'] as [$value, $label])
                                            <div class="rounded-control border border-line bg-surface p-2">
                                                <dt class="truncate text-[0.625rem] uppercase tracking-wide text-muted">{{ $label }}</dt>
                                                <dd class="mt-1 truncate text-sm font-extrabold text-ink">{{ $value }}</dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                    <ul class="mt-3 divide-y divide-line rounded-control border border-line bg-surface px-3">
                                        @foreach ($area['events'] as [$event, $state])
                                            <li class="flex items-center gap-2 py-2 text-xs"><span class="size-1.5 shrink-0 rounded-full bg-emphasis" aria-hidden="true"></span><span class="min-w-0 truncate text-ink">{{ $event }}</span><span class="ml-auto shrink-0 text-muted">{{ $state }}</span></li>
                                        @endforeach
                                    </ul>
                                </div>
                            </x-signal.ui.card>
                        @endforeach
                    </div>
                </div>
            </section>
        @endif

        @if (! empty($product['workflows']) && ! empty($product['guardrails']))
            <section class="bg-page py-14 sm:py-20" aria-labelledby="product-workflows-heading">
                <div class="mx-auto grid max-w-screen-2xl gap-10 px-5 sm:px-8 lg:grid-cols-2">
                    <div>
                        <p class="ui-eyebrow">{{ __('Everyday workflows') }}</p>
                        <h2 id="product-workflows-heading" class="mt-3 text-3xl font-extrabold tracking-tight text-ink">{{ __('Connect. Provision. Deploy.') }}</h2>
                        <ol class="mt-6 grid gap-3">
                            @foreach ($product['workflows'] as [$title, $description])
                                <li>
                                    <x-signal.ui.card class="flex gap-4 p-4">
                                        <span class="grid size-8 shrink-0 place-items-center rounded-full bg-emphasis text-xs font-extrabold text-emphasis-ink">{{ $loop->iteration }}</span>
                                        <div><h3 class="font-bold text-ink">{{ $title }}</h3><p class="mt-1 text-sm leading-6 text-muted">{{ $description }}</p></div>
                                    </x-signal.ui.card>
                                </li>
                            @endforeach
                        </ol>
                    </div>
                    <x-signal.ui.card class="ui-emphasis relative overflow-hidden border-0 p-6 sm:p-8">
                        <p class="text-xs font-extrabold uppercase tracking-[0.16em] text-brand-300">{{ __('Guardrails included') }}</p>
                        <h2 class="mt-3 text-2xl font-extrabold tracking-tight text-emphasis-ink">{{ __('Designed for the difficult day.') }}</h2>
                        <p class="ui-emphasis-muted mt-3 leading-7">{{ __('Failures keep their logs, revision context, and recovery actions. Sensitive values stay encrypted and operations stay owner-scoped.') }}</p>
                        <ul class="mt-6 grid gap-3 sm:grid-cols-2">
                            @foreach ($product['guardrails'] as $guardrail)
                                <li class="flex items-center gap-2 text-sm font-semibold text-emphasis-ink"><span aria-hidden="true">✓</span>{{ $guardrail }}</li>
                            @endforeach
                        </ul>
                    </x-signal.ui.card>
                </div>
            </section>
        @endif

        @if (! empty($product['questions']))
            <section class="border-t border-line bg-surface-muted/60 py-14 sm:py-20" aria-labelledby="product-questions-heading">
                <div class="mx-auto grid max-w-screen-2xl gap-8 px-5 sm:px-8 lg:grid-cols-[.7fr_1.3fr]">
                    <div>
                        <p class="ui-eyebrow">{{ __('Good to know') }}</p>
                        <h2 id="product-questions-heading" class="mt-3 text-3xl font-extrabold tracking-tight text-ink">{{ __('Straight answers.') }}</h2>
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
                    :copy="__('Open the product dashboard with the same account and workspace your team uses across Buildpusher.')"
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
        ]"
        :closing-eyebrow="__('Your :name workspace', ['name' => $product['name']])"
        :closing-copy="__('Sign in to continue to the product dashboard. Its plan and data remain managed separately.')"
        :action-href="$productUrl"
        :action-label="__('Open :name', ['name' => $product['name']])"
    />
</x-signal.layouts.core>
