@props([
    'label',
    'current',
    'icon',
    'items' => [],
    'emptyText' => null,
    'variant' => 'topbar',
])

{{-- A switcher menu. Each item is ['name', 'url', 'current', 'method' => 'get'|'post']; POST items submit a form. --}}
@php($mobile = $variant === 'mobile')

<details
    data-signal-menu
    {{ $attributes->class(['group relative', 'w-full' => $mobile, 'min-w-0 max-w-60' => ! $mobile]) }}
    @click.outside="$el.open = false"
    @keydown.escape.stop="$el.open = false; $el.querySelector('summary')?.focus()"
>
    <summary
        @class([
            'flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-control border border-line bg-surface px-3 text-sm font-bold text-ink hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus [&::-webkit-details-marker]:hidden',
            'w-full' => $mobile,
        ])
        aria-label="{{ $label }}: {{ $current }}"
    >
        <svg class="h-4 w-4 shrink-0 stroke-2 text-primary" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#{{ $icon }}"></use></svg>
        <span class="min-w-0 flex-1 truncate text-left">{{ $current }}</span>
        <svg class="h-3.5 w-3.5 shrink-0 rotate-90 stroke-2 text-muted transition-transform group-open:-rotate-90" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#chevron-right"></use></svg>
    </summary>

    <div @class([
        'z-50 mt-2 grid max-h-[min(60vh,28rem)] min-w-64 gap-1 overflow-y-auto rounded-panel border border-line bg-surface p-2 shadow-panel',
        'relative w-full' => $mobile,
        'absolute left-0 top-full' => ! $mobile,
    ]) role="group" aria-label="{{ $label }}">
        <p class="px-3 py-2 text-[10px] font-extrabold uppercase tracking-[0.16em] text-subtle">{{ $label }}</p>
        @forelse ($items as $item)
            @php($classes = ['flex min-h-10 w-full items-center gap-2 rounded-control px-3 text-left text-sm font-bold focus-visible:outline-2 focus-visible:outline-focus', $item['current'] ? 'bg-primary-soft text-primary' : 'text-muted hover:bg-surface-muted hover:text-ink'])
            @if (($item['method'] ?? 'get') === 'post')
                <form method="POST" action="{{ $item['url'] }}">
                    @csrf
                    <button type="submit" @class($classes) @if ($item['current']) aria-current="true" @endif>
                        <span class="min-w-0 flex-1 truncate">{{ $item['name'] }}</span>
                        @if ($item['current'])<span aria-hidden="true">✓</span>@endif
                    </button>
                </form>
            @else
                <a href="{{ $item['url'] }}" @class($classes) @if ($item['current']) aria-current="page" @endif>
                    <span class="min-w-0 flex-1 truncate">{{ $item['name'] }}</span>
                    @if ($item['current'])<span aria-hidden="true">✓</span>@endif
                </a>
            @endif
        @empty
            @if ($emptyText)
                <p class="px-3 py-2 text-sm text-muted">{{ $emptyText }}</p>
            @endif
        @endforelse
        {{ $slot }}
    </div>
</details>
