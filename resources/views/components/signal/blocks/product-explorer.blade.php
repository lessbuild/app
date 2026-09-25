@props(['products'])

<section id="product-explorer" class="mt-14" aria-labelledby="product-explorer-heading" data-product-explorer>
    <div class="flex flex-col justify-between gap-4 md:flex-row md:items-end">
        <div>
            <p class="ui-eyebrow">{{ __('Product explorer') }}</p>
            <h3 id="product-explorer-heading" class="mt-2 text-2xl font-extrabold tracking-tight text-ink sm:text-3xl">{{ __('A clear view for every kind of work.') }}</h3>
        </div>
        <p class="max-w-md text-sm leading-6 text-muted">{{ __('Explore each app’s role in a shared project. Open its product page for the full feature catalog.') }}</p>
    </div>

    <div class="mt-6">
        <nav class="product-explorer-tabs grid grid-cols-3 rounded-control border border-line bg-surface p-1" aria-label="{{ __('Explore Buildpusher products') }}" data-product-tabs>
            @foreach ($products as $slug => $product)
                <a
                    href="#product-explorer-panel-{{ $slug }}"
                    class="product-explorer-tab"
                    data-product-tab="{{ $slug }}"
                >
                    <x-signal.ui.icon :name="$product['icon']" class="size-4" />
                    <span>{{ $slug === 'deployer' ? __('Deploy') : $product['name'] }}</span>
                </a>
            @endforeach
        </nav>

        @foreach ($products as $slug => $product)
            <article id="product-explorer-panel-{{ $slug }}" class="product-explorer-panel" data-product-panel="{{ $slug }}">
                <div class="grid min-w-0 gap-5 rounded-panel border border-line bg-surface p-4 shadow-soft sm:p-6 md:grid-cols-[.72fr_1.28fr] md:items-center">
                    <div>
                        <p class="product-accent-{{ $product['accent'] }} text-xs font-extrabold uppercase tracking-[0.14em]">{{ $product['eyebrow'] }}</p>
                        <h4 class="mt-2 text-xl font-extrabold tracking-tight text-ink">{{ $product['suite_workflow']['title'] }}</h4>
                        <p class="mt-2 text-sm leading-6 text-muted">{{ $product['suite_workflow']['description'] }}</p>

                        <ul class="mt-4 grid gap-2">
                            @foreach ($product['suite_workflow']['features'] as $feature)
                                <li class="flex items-start gap-2 text-sm font-semibold text-ink">
                                    <x-signal.ui.icon name="check" class="mt-0.5 size-4 shrink-0 text-success" />
                                    <span>{{ $feature }}</span>
                                </li>
                            @endforeach
                        </ul>

                        <x-signal.ui.button :href="route('core.marketing.product', $slug)" variant="secondary" class="mt-5">
                            {{ __('Explore :name', ['name' => $product['name']]) }}
                            <x-signal.ui.icon name="arrow-right" class="size-4" />
                        </x-signal.ui.button>
                    </div>

                    <x-signal.blocks.product-preview
                        :product-key="$slug"
                        :product="$product"
                        :heading-id="'product-explorer-preview-'.$slug"
                        class="product-explorer-preview"
                    />
                </div>
            </article>
        @endforeach
    </div>
</section>
