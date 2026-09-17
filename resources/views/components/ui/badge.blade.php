@props(['tone' => 'neutral'])

@php($tones = ['neutral', 'accent', 'success', 'warning', 'danger'])
@php($tone = in_array($tone, $tones, true) ? $tone : 'neutral')

<span {{ $attributes->class(['ui-badge', 'ui-badge--'.$tone]) }}>
    {{ $slot }}
</span>
