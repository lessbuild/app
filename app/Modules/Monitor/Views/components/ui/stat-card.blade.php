@props(['label', 'value', 'caption' => null, 'change' => null, 'icon' => 'activity', 'tone' => 'slate'])

@php($signalTone = match ($tone) {
    'primary', 'violet' => 'accent',
    'sky', 'info' => 'info',
    'green', 'emerald', 'success' => 'success',
    'amber', 'warning' => 'warning',
    'red', 'danger' => 'danger',
    default => 'neutral',
})

<x-signal.ui.stat :label="$label" :value="$value" :description="$caption" :icon="$icon" :change="$change" :tone="$signalTone" {{ $attributes->class(['min-w-0']) }} />
