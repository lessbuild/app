@props(['label'])

<nav {{ $attributes->class(['ui-local-nav']) }} aria-label="{{ $label }}">
    <div class="ui-local-nav__scroll">
        {{ $slot }}
    </div>
</nav>
