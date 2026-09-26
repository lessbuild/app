<nav class="flex-1 space-y-6" aria-label="{{ $navigationLabel }}">
    @foreach($workspaceNavigation as $group => $items)
        <section class="space-y-1">
            <h2 class="mb-3 px-3 text-[10px] font-extrabold uppercase tracking-[0.18em] text-subtle">{{ $group }}</h2>
            @foreach($items as $item)
                <x-monitor::ui.navigation-link :item="$item">
                    @if($item['route'] === 'issues.index' && $workspaceOpenIssues > 0)
                        <x-monitor::ui.badge tone="danger" class="ml-auto" aria-label="{{ $workspaceOpenIssues }} open issues">{{ $workspaceOpenIssues }}</x-monitor::ui.badge>
                    @endif
                </x-monitor::ui.navigation-link>
            @endforeach
        </section>
    @endforeach
</nav>
