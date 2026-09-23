@props(['group'])

@php
    $items = $group['items'] ?? [];
    $active = collect($items)->contains(function (array $item): bool {
        $patterns = $item['active'] ?? [];

        return is_bool($patterns) ? $patterns : ((array) $patterns !== [] && request()->routeIs(...(array) $patterns));
    });
@endphp

@if (count($items) === 1)
    <x-signal.layouts.navigation-link :item="$items[0]" />
@elseif ($items !== [])
    <x-signal.ui.menu
        align="left"
        class="ui-topbar-nav-menu"
        data-signal-menu
        :trigger-class="implode(' ', [
            'min-h-10 items-center gap-2 rounded-control px-3 text-sm font-bold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus',
            $active ? 'bg-primary-soft text-primary' : 'text-muted hover:bg-surface-muted hover:text-ink',
        ])"
        panel-class="min-w-56"
        @click.outside="$el.open = false"
        @keydown.escape.stop="$el.open = false; $el.querySelector('summary')?.focus()"
    >
        <x-slot:trigger>
            <span>{{ $group['label'] }}</span>
            <svg class="h-3.5 w-3.5 rotate-90 stroke-2 transition-transform group-open:-rotate-90" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#chevron-right"></use></svg>
        </x-slot:trigger>
        @foreach ($items as $item)
            <x-signal.layouts.navigation-link :item="$item" class="w-full justify-start" />
        @endforeach
    </x-signal.ui.menu>
@endif
