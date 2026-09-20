@props([
    'open' => false,
    'label' => __('Filters'),
    'summary' => null,
])

<details data-mobile-filter-panel {{ $attributes->class(['ui-card', 'group', 'overflow-hidden']) }} @if($open) open @endif>
    <summary data-mobile-filter-summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-4 px-4 py-3 font-bold text-primary [&::-webkit-details-marker]:hidden">
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
<button type="button" data-mobile-filter-backdrop class="fixed inset-0 z-40 hidden border-0 bg-slate-950/50 p-0" aria-label="{{ __('Close filters') }}"></button>
