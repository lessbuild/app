@props([
    'id' => null,
    'summary' => null,
    'open' => true,
    'mobileOpen' => false,
])

@if ($id)
<details id="{{ $id }}"
@else
<details
@endif
    {{ $attributes->class(['ui-responsive-details ui-card group overflow-hidden']) }}
    @if ($open) open @endif
    data-responsive-details
    data-responsive-details-mobile-expanded="{{ $mobileOpen ? 'true' : 'false' }}"
>
    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 font-bold text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 [&::-webkit-details-marker]:hidden">
        <span class="min-w-0">
            <span class="block text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Insights') }}</span>
            @if ($summary)
                <span class="mt-1 block truncate text-sm font-normal text-secondary">{{ $summary }}</span>
            @endif
        </span>
        <span class="shrink-0 text-xl font-normal text-secondary transition group-open:rotate-45" aria-hidden="true">+</span>
    </summary>
    <div class="ui-responsive-details__content ui-insights__content border-t border-primary p-3 sm:p-4 lg:border-0">
        {{ $slot }}
    </div>
</details>
