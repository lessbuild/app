@props(['name', 'current'])

<section
    id="page-panel-{{ $name }}"
    role="tabpanel"
    aria-labelledby="page-tab-{{ $name }}"
    data-page-panel="{{ $name }}"
    @if ($name !== $current) hidden @endif
    {{ $attributes->class(['grid min-w-0 gap-6']) }}
>
    {{ $slot }}
</section>
