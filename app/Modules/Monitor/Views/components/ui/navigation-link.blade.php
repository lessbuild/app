@props(['item', 'variant' => 'sidebar'])

@php
    $classes = [
        'sidebar' => 'app-sidebar-link',
        'bottom' => 'ui-bottom-nav-link',
        'command' => 'ui-command-item flex items-center justify-between gap-3 rounded-card px-3 py-3 text-sm font-bold text-muted hover:bg-surface-muted hover:text-ink',
        'fallback' => 'ui-btn ui-btn-secondary ui-btn-sm',
    ];
@endphp

<a href="{{ $item['href'] }}" @if($item['active']) aria-current="page" @endif {{ $attributes->class([$classes[$variant] ?? $classes['sidebar']]) }}>
    @if($variant === 'command')
        <span class="flex min-w-0 items-center gap-3"><x-monitor::icon :name="$item['icon']" class="h-[17px] w-[17px] shrink-0" /><span>{{ $item['label'] }}</span></span>
        <x-monitor::icon name="arrow-right" class="h-[14px] w-[14px] shrink-0 text-subtle" />
    @else
        <x-monitor::icon :name="$item['icon']" class="h-[17px] w-[17px] shrink-0" /><span>{{ $item['label'] }}</span>
    @endif
    {{ $slot }}
</a>
