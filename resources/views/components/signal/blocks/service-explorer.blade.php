@props(['services'])

{{-- The Signal product explorer: one tab per service, each showing what the service is for and a glimpse of it. --}}
<section id="service-explorer" {{ $attributes->class(['scroll-mt-20']) }} aria-labelledby="service-explorer-heading" x-data="{ tab: @js($services[0]->key()) }">
    <div class="flex flex-col justify-between gap-4 md:flex-row md:items-end">
        <div>
            <p class="ui-eyebrow">{{ __('Product explorer') }}</p>
            <h3 id="service-explorer-heading" class="mt-2 text-2xl font-extrabold tracking-tight text-ink sm:text-3xl">{{ __('A clear view for every kind of work.') }}</h3>
        </div>
        <p class="hidden max-w-md text-sm leading-6 text-muted sm:block">{{ __('See each service’s part in a project. Open its page for everything it does.') }}</p>
    </div>

    <div class="product-explorer-tabs mt-6 grid grid-cols-4 rounded-control border border-line bg-surface p-1" role="tablist" aria-label="{{ __('Explore the services') }}">
        @foreach ($services as $service)
            <button
                type="button"
                role="tab"
                id="service-tab-{{ $service->key() }}"
                aria-controls="service-panel-{{ $service->key() }}"
                class="product-explorer-tab"
                aria-selected="{{ $loop->first ? 'true' : 'false' }}"
                tabindex="{{ $loop->first ? '0' : '-1' }}"
                :aria-selected="tab === @js($service->key()) ? 'true' : 'false'"
                :tabindex="tab === @js($service->key()) ? 0 : -1"
                x-on:click="tab = @js($service->key())"
                x-on:keydown.right.prevent="$el.nextElementSibling?.click(); $el.nextElementSibling?.focus()"
                x-on:keydown.left.prevent="$el.previousElementSibling?.click(); $el.previousElementSibling?.focus()"
            >
                <x-signal.ui.icon :name="config('marketing.services.'.$service->key().'.icon')" class="size-4" />
                <span>{{ $service->name() }}</span>
            </button>
        @endforeach
    </div>

    @foreach ($services as $service)
        @php($copy = config('marketing.services.'.$service->key()))
        <div
            id="service-panel-{{ $service->key() }}"
            role="tabpanel"
            tabindex="0"
            aria-labelledby="service-tab-{{ $service->key() }}"
            class="product-explorer-panel"
            @unless ($loop->first) hidden @endunless
            :hidden="tab !== @js($service->key())"
        >
            <div class="grid min-w-0 gap-5 rounded-panel border border-line bg-surface p-4 shadow-soft sm:p-6 md:grid-cols-[.8fr_1.2fr] md:items-center">
                <div>
                    <p class="product-accent-{{ $copy['accent'] }} text-xs font-extrabold uppercase tracking-[0.14em]">{{ __($copy['eyebrow']) }}</p>
                    <h4 class="mt-2 text-xl font-extrabold tracking-tight text-ink">{{ __($copy['suite']['title']) }}</h4>
                    <p class="mt-2 text-sm leading-6 text-muted">{{ __($copy['suite']['description']) }}</p>
                    <ul class="mt-4 hidden gap-2 sm:grid">
                        @foreach ($copy['suite']['features'] as $feature)
                            <li class="flex items-start gap-2 text-sm font-semibold text-ink"><x-signal.ui.icon name="check" class="mt-0.5 size-4 shrink-0 text-success" /><span>{{ __($feature) }}</span></li>
                        @endforeach
                    </ul>
                    <x-signal.ui.button :href="route('features', $service->key())" variant="secondary" class="mt-5">
                        {{ __('Explore :service', ['service' => $service->name()]) }} <x-signal.ui.icon name="arrow-right" class="size-4" />
                    </x-signal.ui.button>
                </div>
                <x-signal.blocks.service-preview :copy="$copy" :heading-id="'service-preview-'.$service->key()" class="product-explorer-preview" />
            </div>
        </div>
    @endforeach
</section>
