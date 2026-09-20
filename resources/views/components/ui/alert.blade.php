@props(['tone' => 'info'])

@php($tones = ['success', 'info', 'warning', 'danger'])
@php($tone = in_array($tone, $tones, true) ? $tone : 'info')

<div data-ui-feedback="alert" {{ $attributes->class(['ui-alert', 'ui-alert--'.$tone]) }}>
    {{ $slot }}
</div>
