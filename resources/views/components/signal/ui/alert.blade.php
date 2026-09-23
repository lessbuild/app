@props(['tone' => 'info'])

@php($tones = ['success', 'info', 'warning', 'danger'])
@php($tone = in_array($tone, $tones, true) ? $tone : 'info')

@php($signalTone = $tone === 'info' ? null : $tone)

<div data-ui-feedback="alert" {{ $attributes->class(['ui-alert', 'ui-alert--'.$tone, 'ui-alert-'.$signalTone => $signalTone !== null]) }}>
    {{ $slot }}
</div>
