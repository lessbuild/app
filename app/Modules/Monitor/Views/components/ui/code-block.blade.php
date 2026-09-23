@props(['id', 'language' => null])

<div data-copy-host {{ $attributes->class(['ui-card overflow-hidden']) }}>
    <div class="flex items-center justify-between gap-3 border-b border-line bg-surface px-4 py-3">
        <span class="text-[11px] font-bold uppercase tracking-wider text-subtle">{{ $language ?? 'Code' }}</span>
        <x-monitor::ui.button type="button" variant="secondary" size="sm" data-copy-target="{{ $id }}">
            <span data-copy-label>Copy</span>
        </x-monitor::ui.button>
    </div>
    <pre class="library-code max-h-[30rem] rounded-none"><code id="{{ $id }}">{{ $slot }}</code></pre>
</div>
