<nav class="ui-breadcrumb" aria-label="{{ __('Breadcrumb') }}">
    <a href="{{ $route }}" class="ui-breadcrumb__link">
        <svg class="ui-breadcrumb__icon" aria-hidden="true">
            <use xlink:href="/assets/images/icons.svg#chevron-left"></use>
        </svg>
        <span>{{ $title }}</span>
    </a>
    @isset($buttons)
        <div class="flex flex-wrap items-center gap-2">{{ $buttons }}</div>
    @endisset
</nav>
