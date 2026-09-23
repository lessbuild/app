@props(['item'])

@php
    $activePatterns = $item['active'] ?? [];
    $active = $activePatterns !== [] && request()->routeIs(...$activePatterns);
    $href = route($item['route']).($item['anchor'] ?? '');
@endphp

<a
    href="{{ $href }}"
    @if ($active) aria-current="page" @endif
    {{ $attributes->class([
        'inline-flex min-h-10 items-center gap-2 rounded-control px-3 text-sm font-bold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus',
        'bg-primary-soft text-primary' => $active,
        'text-muted hover:bg-surface-muted hover:text-ink' => ! $active,
    ]) }}
>
    @if (! empty($item['icon']))
        <svg class="h-4 w-4 shrink-0 stroke-2" aria-hidden="true">
            <use xlink:href="/assets/images/icons.svg#{{ $item['icon'] }}"></use>
        </svg>
    @endif
    <span>{{ $item['label'] }}</span>
    @if (($item['badge'] ?? 0) > 0)
        <span class="ui-badge ui-badge-danger px-2 py-0.5 text-[10px]" aria-label="{{ __('Unread notifications: :count', ['count' => $item['badge']]) }}">
            {{ $item['badge'] > 99 ? '99+' : $item['badge'] }}
        </span>
    @endif
</a>
