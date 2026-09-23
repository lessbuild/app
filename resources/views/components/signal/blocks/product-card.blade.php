@props(['name', 'eyebrow', 'summary', 'features' => [], 'href'])

<x-signal.ui.card tone="interactive" {{ $attributes->class(['flex h-full flex-col p-6 sm:p-7']) }}>
    <div class="flex items-start justify-between gap-4">
        <div>
            <p class="ui-eyebrow">{{ $eyebrow }}</p>
            <h3 class="mt-2 text-2xl font-extrabold tracking-tight text-ink">{{ $name }}</h3>
        </div>
        <x-signal.ui.badge tone="accent">{{ __('Separate plan') }}</x-signal.ui.badge>
    </div>

    <p class="mt-4 text-sm leading-6 text-muted">{{ $summary }}</p>

    <ul class="mt-5 grid gap-3" aria-label="{{ __(':name highlights', ['name' => $name]) }}">
        @foreach ($features as $feature)
            <li class="flex items-start gap-3 text-sm font-semibold text-ink">
                <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full bg-emphasis text-[0.65rem] font-black text-emphasis-ink" aria-hidden="true">✓</span>
                <span>{{ $feature }}</span>
            </li>
        @endforeach
    </ul>

    <div class="mt-auto pt-7">
        <x-signal.ui.button :href="$href" variant="secondary" class="w-full justify-center">
            {{ __('Explore :name', ['name' => $name]) }}
            <span aria-hidden="true">→</span>
        </x-signal.ui.button>
    </div>
</x-signal.ui.card>
