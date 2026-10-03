@props(['tabs', 'current', 'url', 'label' => null])

{{--
    A page's sections as tabs. Every panel is on the page; resources/js/page-tabs.js switches between them without a
    request and keeps `?tab=` in the address, so refreshing, bookmarks and redirects after saving open the same tab.
    Without JavaScript the tabs are ordinary links to `?tab=…`.
--}}
<x-signal.ui.local-nav :label="$label ?? __('Sections')" data-page-tabs {{ $attributes }}>
    <div role="tablist" aria-label="{{ $label ?? __('Sections') }}" class="contents">
        @foreach ($tabs as $key => $title)
            <a
                href="{{ $url }}?tab={{ $key }}"
                id="page-tab-{{ $key }}"
                role="tab"
                class="ui-local-nav__link"
                aria-controls="page-panel-{{ $key }}"
                aria-selected="{{ $key === $current ? 'true' : 'false' }}"
                tabindex="{{ $key === $current ? '0' : '-1' }}"
                data-page-tab="{{ $key }}"
                @if ($key === $current) aria-current="page" @endif
            >{{ $title }}</a>
        @endforeach
    </div>
</x-signal.ui.local-nav>
