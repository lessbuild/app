@props(['tone' => 'neutral'])

@php($tones = ['neutral', 'accent', 'success', 'warning', 'danger'])
@php($tone = in_array($tone, $tones, true) ? $tone : 'neutral')
@php($signalTone = match ($tone) {
    'accent' => 'primary',
    'neutral' => 'soft',
    default => $tone,
})

<span {{ $attributes->class(['ui-badge', 'ui-badge--'.$tone, 'ui-badge-'.$signalTone]) }}>
    {{ $slot }}
</span>
