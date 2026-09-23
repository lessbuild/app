@props(['tone' => 'default'])

@php($tones = ['default', 'muted', 'interactive'])
@php($tone = in_array($tone, $tones, true) ? $tone : 'default')

<div {{ $attributes->class(['ui-card', 'ui-card--'.$tone => $tone !== 'default']) }}>
    {{ $slot }}
</div>
