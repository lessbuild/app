<x-ui.alert tone="warning" class="p-3">
    {{ __('This community script runs as root. Read every command and verify package sources, downloads, and destructive operations before using it.') }}
</x-ui.alert>

<div class="mt-4 flex flex-wrap items-center justify-between gap-3">
    <p class="text-sm text-secondary">
        {{ __('Published by :author. No commands are executed from this preview.', ['author' => $recipe->user->name ?? __('the contributor')]) }}
    </p>
    <x-ui.button href="{{ route('gallery.show', $recipe) }}" variant="secondary">
        {{ __('Open recipe details') }}
    </x-ui.button>
</div>

<pre class="mt-4 max-h-[min(60vh,36rem)] overflow-auto rounded-lg bg-gray-950 p-4 text-sm leading-6 text-gray-100"><code>{{ $recipe->script }}</code></pre>
