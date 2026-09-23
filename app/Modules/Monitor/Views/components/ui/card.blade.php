@props(['padding' => 'p-5', 'shadow' => true])

<div {{ $attributes->class(['ui-card', $padding, 'shadow-none' => ! $shadow]) }}>
    {{ $slot }}
</div>
