@props(['group'])

@php
    $items = $group['items'] ?? [];
    $active = collect($items)->contains(fn (array $item): bool => ! empty($item['active']) && request()->routeIs(...$item['active']));
@endphp

@if (count($items) === 1)
    <x-layouts.topbar-navigation-link :item="$items[0]" />
@elseif ($items !== [])
    <x-ui.menu
        align="left"
        class="ui-topbar-nav-menu"
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
            <x-layouts.topbar-navigation-link :item="$item" class="w-full justify-start" />
        @endforeach
    </x-ui.menu>
@endif
