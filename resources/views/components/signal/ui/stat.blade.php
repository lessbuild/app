@props([
    'label' => null,
    'value' => null,
    'description' => null,
    'icon' => null,
    'change' => null,
    'tone' => 'neutral',
    'as' => 'dl',
    'slotMode' => false,
])

@php($tag = in_array($as, ['dl', 'div', 'a'], true) ? $as : 'dl')

@if ($slotMode)
    <{{ $tag }} {{ $attributes->class(['ui-stat']) }}>
        {{ $slot }}
    </{{ $tag }}>
@else
<dl {{ $attributes->class(['ui-stat']) }}>
    <dt class="ui-stat__label flex min-w-0 flex-wrap items-center justify-between gap-2 text-xs font-bold text-muted">
        <span class="flex min-w-0 items-center gap-2">
            @if ($icon)
                <svg class="h-[17px] w-[17px] shrink-0" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#{{ $icon }}"></use></svg>
            @endif
            {{ $label }}
        </span>
        @if ($change !== null)
            <x-signal.ui.badge :tone="$tone">{{ $change }}</x-signal.ui.badge>
        @endif
    </dt>
    <dd class="ui-stat__value mt-4 text-3xl font-extrabold tracking-tight text-ink">{{ $value }}</dd>
    @if ($description)
        <dd class="ui-stat__description mt-2 text-xs text-muted">{{ $description }}</dd>
    @endif
</dl>
@endif
