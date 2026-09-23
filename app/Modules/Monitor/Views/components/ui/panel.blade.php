@props(['padding' => 'p-5 sm:p-6', 'as' => 'div', 'shadow' => true])
@php($tag = in_array($as, ['div', 'section', 'article', 'aside', 'form', 'fieldset'], true) ? $as : 'div')

<{{ $tag }} {{ $attributes->class(['ui-panel', $padding, 'shadow-none' => ! $shadow]) }}>
    {{ $slot }}
</{{ $tag }}>
