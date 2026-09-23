@props([
    'label',
    'value',
    'description' => null,
])

<div {{ $attributes->class(['ui-stat']) }}>
    <dt class="ui-stat__label text-xs font-bold text-muted">{{ $label }}</dt>
    <dd class="ui-stat__value mt-4 text-3xl font-extrabold tracking-tight text-ink">{{ $value }}</dd>
    @if ($description)
        <dd class="ui-stat__description mt-2 text-xs text-muted">{{ $description }}</dd>
    @endif
</div>
