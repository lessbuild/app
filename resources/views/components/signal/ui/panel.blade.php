@props([
    'as' => 'div',
])

@php($tag = in_array($as, ['a', 'div', 'section', 'article', 'aside', 'form', 'fieldset', 'details', 'dl', 'li', 'p'], true) ? $as : 'div')
@php($classes = collect(preg_split('/\s+/', (string) $attributes->get('class', ''), -1, PREG_SPLIT_NO_EMPTY))->reject(fn (string $class): bool => $class === 'ui-panel')->all())

<{{ $tag }} {{ $attributes->except('class')->class(array_merge(['ui-panel'], $classes)) }}>
    {{ $slot }}
</{{ $tag }}>
