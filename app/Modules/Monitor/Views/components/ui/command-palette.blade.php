@props(['navigation'])

<x-signal.overlays.modal
    id="global-command"
    :title="'Go anywhere in '.config('app.name')"
    description="Search your workspace without leaving the keyboard."
    bodyClass="p-5 sm:p-6"
    class="ui-command-dialog"
    data-global-command-dialog
>
        <label class="sr-only" for="global-command-search">Search workspace navigation</label>
        <div class="relative mt-6"><span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-subtle"><x-monitor::icon name="search" class="h-4 w-4" /></span><x-signal.ui.input class="pl-10" id="global-command-search" type="search" placeholder="Try issues, monitors, or settings" autocomplete="off" aria-controls="global-command-list" data-global-command-search :restore="false" /></div>
        <nav id="global-command-list" class="mt-4 grid max-h-[min(22rem,48vh)] gap-1 overflow-y-auto" aria-label="Quick workspace navigation" data-global-command-list>
            @foreach($navigation as $group => $items)
                @foreach($items as $item)
                    <x-monitor::ui.navigation-link :item="$item" variant="command" data-global-command-item data-command-keywords="{{ $group }} {{ $item['label'] }}" />
                @endforeach
            @endforeach
        </nav>
        <p class="ui-help mt-4 hidden" role="status" aria-live="polite" data-global-command-empty>No matching destination. Try a shorter search.</p>
        <div class="mt-5 flex flex-wrap items-center gap-2 text-[11px] text-subtle"><span>Navigate</span><kbd class="ui-kbd">↑</kbd><kbd class="ui-kbd">↓</kbd><span class="ml-2">Open</span><kbd class="ui-kbd">Enter</kbd><span class="ml-2">Close</span><kbd class="ui-kbd">Esc</kbd></div>
</x-signal.overlays.modal>
