@props([
    'tone' => 'default',
    'as' => 'div',
    'padding' => null,
    'shadow' => true,
])

@php($tones = ['default', 'muted', 'interactive'])
@php($tone = in_array($tone, $tones, true) ? $tone : 'default')
@php($tag = in_array($as, ['a', 'div', 'section', 'article', 'aside', 'figure', 'form', 'fieldset', 'details', 'summary', 'dl', 'li', 'p'], true) ? $as : 'div')

<{{ $tag }} {{ $attributes->class(['ui-card', 'ui-card--'.$tone => $tone !== 'default', $padding, 'shadow-none' => ! $shadow]) }}>
    {{ $slot }}
</{{ $tag }}>
