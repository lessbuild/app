<x-signal.ui.alert tone="warning" class="p-3">
    {{ __('This community script runs as root. Read every command and verify package sources, downloads, and destructive operations before using it.') }}
</x-signal.ui.alert>

<div class="mt-4 flex flex-wrap items-center justify-between gap-3">
    <p class="text-sm text-muted">
        {{ __('Published by :author. No commands are executed from this preview.', ['author' => $recipe->user->name ?? __('the contributor')]) }}
    </p>
    <x-signal.ui.button href="{{ route('gallery.show', $recipe) }}" variant="secondary">
        {{ __('Open recipe details') }}
    </x-signal.ui.button>
</div>

<pre class="ui-console mt-4 max-h-[min(60vh,36rem)] overflow-auto p-4 text-sm leading-6"><code>{{ $recipe->script }}</code></pre>
