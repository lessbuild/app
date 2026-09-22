@props([
    'item',
    'mobile' => false,
])

@php
    $activePatterns = $item['active'] ?? [];
    $active = $activePatterns !== [] && request()->routeIs(...$activePatterns);
    $href = route($item['route']).($item['anchor'] ?? '');
@endphp

<a href="{{ $href }}"
    @if ($active) aria-current="page" @endif
    @class([
        'group flex min-w-0 items-center gap-3 rounded-lg text-sm transition-colors focus-visible:relative focus-visible:z-10',
        'min-h-10 px-3 py-2.5' => ! $mobile,
        'min-h-[46px] border px-3 py-2 text-xs font-semibold shadow-xs' => $mobile,
        'bg-surface-muted font-semibold text-ink' => $active && ! $mobile,
        'border-line bg-[var(--ui-primary-soft)] text-[var(--ui-primary)]' => $active && $mobile,
        'text-muted hover:bg-surface-muted hover:text-ink' => ! $active && ! $mobile,
        'border-line bg-surface text-ink hover:bg-surface-muted' => ! $active && $mobile,
    ])
>
    <svg @class([
        'h-4 w-4 shrink-0 stroke-2',
        'text-[var(--ui-primary)]' => $active,
        'text-muted group-hover:text-[var(--ui-primary)]' => ! $active,
    ]) aria-hidden="true">
        <use xlink:href="/assets/images/icons.svg#{{ $item['icon'] }}"></use>
    </svg>
    <span class="min-w-0 flex-1 break-words">{{ $item['label'] }}</span>
    @if (($item['badge'] ?? 0) > 0)
        <span
            class="ui-badge ui-badge-danger px-2 py-0.5 text-[10px]"
            aria-label="{{ __('Unread notifications: :count', ['count' => $item['badge']]) }}"
        >
            {{ $item['badge'] > 99 ? '99+' : $item['badge'] }}
        </span>
    @endif
</a>
