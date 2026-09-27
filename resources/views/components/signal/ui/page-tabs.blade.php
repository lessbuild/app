@props(['tabs', 'current', 'url', 'label' => null])

{{-- Sections of one page as links (`?tab=…`), so each tab has its own URL and works without JavaScript. --}}
<x-signal.ui.local-nav :label="$label ?? __('Sections')" {{ $attributes }}>
    @foreach ($tabs as $key => $title)
        <a href="{{ $url }}?tab={{ $key }}" class="ui-local-nav__link" @if ($key === $current) aria-current="page" @endif>{{ $title }}</a>
    @endforeach
</x-signal.ui.local-nav>
