@props(['icon', 'title', 'description'])

<x-signal.ui.card as="article" {{ $attributes->class(['h-full p-5 sm:p-6']) }}>
    <span class="grid size-11 place-items-center rounded-xl bg-primary-soft text-primary">
        <x-signal.ui.icon :name="$icon" class="size-5" />
    </span>
    <h3 class="mt-5 text-lg font-extrabold text-ink">{{ __($title) }}</h3>
    <p class="mt-2 text-sm leading-6 text-muted">{{ __($description) }}</p>
</x-signal.ui.card>
