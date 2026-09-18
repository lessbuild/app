@props([
    'open' => false,
    'label' => __('Filters'),
    'summary' => null,
])

<details {{ $attributes->class(['ui-card', 'group', 'overflow-hidden']) }} @if($open) open @endif>
    <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-4 py-3 font-bold text-primary [&::-webkit-details-marker]:hidden">
        <span>{{ $label }}</span>
        <span class="flex items-center gap-2">
            @if ($summary)
                <x-ui.badge tone="accent">{{ $summary }}</x-ui.badge>
            @endif
            <span class="text-lg leading-none text-secondary transition group-open:rotate-45" aria-hidden="true">+</span>
        </span>
    </summary>
    <div class="border-t border-primary p-4">
        {{ $slot }}
    </div>
</details>
