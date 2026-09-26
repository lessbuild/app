@props(['navigation'])

<noscript>
    <nav class="border-t border-line bg-surface px-5 py-3 pb-24 lg:hidden" aria-label="Application navigation without JavaScript">
        <div class="flex flex-wrap gap-2">
            @foreach($navigation as $items)
                @foreach($items as $item)<x-monitor::ui.navigation-link :item="$item" variant="fallback" />@endforeach
            @endforeach
        </div>
    </nav>
</noscript>
<nav class="ui-bottom-nav lg:hidden" aria-label="Quick application navigation">
    @foreach(collect($navigation)->flatten(1)->take(4) as $item)
        <x-monitor::ui.navigation-link :item="$item" variant="bottom" />
    @endforeach
</nav>
