@props([
    'align' => 'left',
    'triggerClass' => '',
    'panelClass' => '',
])

@php($align = in_array($align, ['left', 'right'], true) ? $align : 'left')

<details {{ $attributes->class(['ui-menu group relative']) }}>
    <summary class="ui-menu__trigger {{ $triggerClass }}">
        @isset($trigger)
            {{ $trigger }}
        @endisset
    </summary>
    <div class="ui-menu__panel ui-menu__panel--{{ $align }} {{ $panelClass }}">
        {{ $slot }}
    </div>
</details>
