@props(['tone' => 'info', 'as' => 'div'])

@php($tones = ['success', 'info', 'warning', 'danger'])
@php($tone = in_array($tone, $tones, true) ? $tone : 'info')
@php($tag = in_array($as, ['div', 'p', 'section', 'aside'], true) ? $as : 'div')

@php($signalTone = $tone === 'info' ? null : $tone)

<{{ $tag }} data-ui-feedback="alert" {{ $attributes->class(['ui-alert', 'ui-alert--'.$tone, 'ui-alert-'.$signalTone => $signalTone !== null]) }}>
    {{ $slot }}
</{{ $tag }}>
