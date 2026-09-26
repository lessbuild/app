@props(['type' => 'submit', 'variant' => 'primary', 'size' => 'default', 'href' => null, 'disabled' => false])

@php($variant = match ($variant) {
    'quiet' => 'ghost',
    default => $variant,
})

<x-signal.ui.button :type="$type" :variant="$variant" :size="$size" :href="$href" :disabled="$disabled" {{ $attributes }}>{{ $slot }}</x-signal.ui.button>
