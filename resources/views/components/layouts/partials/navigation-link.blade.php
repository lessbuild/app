@props([
    'item',
    'mobile' => false,
])

@php
    $activePatterns = $item['active'] ?? [];
    $active = $activePatterns !== [] && request()->routeIs(...$activePatterns);
    $href = route($item['route']).($item['anchor'] ?? '');
@endphp

<a
    href="{{ $href }}"
    @if ($active) aria-current="page" @endif
    @class([
        'group flex min-w-0 items-center gap-3 rounded-lg text-sm transition-colors focus-visible:relative focus-visible:z-10',
        'min-h-10 px-3 py-2.5' => ! $mobile,
        'min-h-[46px] border px-3 py-2 text-xs font-semibold shadow-xs' => $mobile,
        'bg-secondary font-semibold text-primary' => $active && ! $mobile,
        'border-slate-700 bg-slate-700 text-white' => $active && $mobile,
        'text-secondary hover:bg-secondary hover:text-primary' => ! $active && ! $mobile,
        'border-primary bg-primary text-primary hover:bg-secondary' => ! $active && $mobile,
    ])
>
    <svg @class([
        'h-4 w-4 shrink-0 stroke-2',
        'text-ternary' => $active,
        'text-secondary group-hover:text-ternary' => ! $active,
    ]) aria-hidden="true">
        <use xlink:href="/assets/images/icons.svg#{{ $item['icon'] }}"></use>
    </svg>
    <span class="min-w-0 flex-1 break-words">{{ $item['label'] }}</span>
    @if (($item['badge'] ?? 0) > 0)
        <span
            class="rounded-full bg-red-600 px-2 py-0.5 text-[10px] font-semibold text-white"
            aria-label="{{ __('Unread notifications: :count', ['count' => $item['badge']]) }}"
        >
            {{ $item['badge'] > 99 ? '99+' : $item['badge'] }}
        </span>
    @endif
</a>
