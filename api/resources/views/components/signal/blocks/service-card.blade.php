@props(['service', 'copy'])

{{-- A service in the product suite: what it's for, its highlights and a link to its page. --}}
<x-signal.ui.card tone="interactive" as="article" {{ $attributes->class(['product-preview-'.$copy['accent'], 'flex h-full flex-col p-5 sm:p-6']) }}>
    <span class="product-icon-{{ $copy['accent'] }} grid size-10 place-items-center rounded-xl" aria-hidden="true"><x-signal.ui.icon :name="$copy['icon']" class="size-5" /></span>
    <p class="product-accent-{{ $copy['accent'] }} mt-4 text-xs font-extrabold uppercase tracking-[0.14em]">{{ __($copy['eyebrow']) }}</p>
    <h3 class="mt-2 text-xl font-extrabold tracking-tight text-ink">{{ $service->name() }}</h3>
    <p class="mt-3 text-sm leading-6 text-muted">{{ __($copy['card_summary']) }}</p>
    <ul class="mt-5 grid gap-3" aria-label="{{ __(':service highlights', ['service' => $service->name()]) }}">
        @foreach ($copy['card_features'] as $feature)
            <li class="flex items-start gap-3 text-sm font-semibold text-ink">
                <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full bg-emphasis text-emphasis-ink" aria-hidden="true"><x-signal.ui.icon name="check" class="size-3" /></span>
                <span>{{ __($feature) }}</span>
            </li>
        @endforeach
    </ul>
    <div class="mt-auto pt-6">
        <x-signal.ui.button :href="route('features', $service->key())" variant="secondary" class="w-full justify-center">
            {{ __('Explore :service', ['service' => $service->name()]) }} <x-signal.ui.icon name="arrow-right" class="size-4" />
        </x-signal.ui.button>
    </div>
</x-signal.ui.card>
