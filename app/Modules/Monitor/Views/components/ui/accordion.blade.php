@props(['title', 'open' => false])

<x-signal.ui.card as="details" tone="muted" padding="p-4" :shadow="false" :open="$open" {{ $attributes }}>
    <summary class="cursor-pointer text-xs font-extrabold text-ink">{{ $title }}</summary>
    <div class="mt-4 space-y-4">{{ $slot }}</div>
</x-signal.ui.card>
