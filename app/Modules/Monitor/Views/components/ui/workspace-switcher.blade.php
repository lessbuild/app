@props(['workspace', 'workspaces'])

<details {{ $attributes->class(['relative']) }}>
    <summary class="flex cursor-pointer list-none items-center justify-between gap-2 rounded-panel border border-line bg-surface-muted p-3">
        <span class="min-w-0"><span class="block truncate text-xs font-extrabold text-ink">{{ $workspace->name }}</span><span class="mt-1 block text-[11px] leading-4 text-muted">Switch workspace</span></span>
        <x-monitor::icon name="chevron-down" class="h-4 w-4 shrink-0 text-subtle" />
    </summary>
    <div class="ui-popover inset-x-0 z-30 mt-2">
        @foreach($workspaces as $option)
            <form method="POST" action="{{ route('monitor.workspaces.switch', $option) }}">
                @csrf
                <x-monitor::ui.button variant="quiet" size="sm" class="w-full justify-start text-left">
                    <span class="min-w-0 truncate">{{ $option->name }}</span>
                    @if($option->id === $workspace->id)<x-monitor::icon name="check" class="ml-auto h-4 w-4 shrink-0 text-primary" /><span class="sr-only">Current workspace</span>@endif
                </x-monitor::ui.button>
            </form>
        @endforeach
        <x-monitor::ui.button :href="route('monitor.workspaces.create')" variant="quiet" size="sm" class="mt-1 w-full justify-start border-t border-line"><x-monitor::icon name="plus" class="h-4 w-4" />New workspace</x-monitor::ui.button>
    </div>
</details>
