<x-layouts.app>
    <x-layouts.partials.breadcrumbs
        :route="route('gallery.show', $recipe)"
        :title="__('Back to gallery recipe')"
    />

    <x-layouts.partials.heading
        :title="__('Review gallery changes')"
        :description="$recipe->name"
    >
        <x-slot:buttons>
            <x-ui.button href="{{ route('recipes.show', ['recipe' => $copy, 'dialog' => 'edit-recipe']) }}" variant="secondary">{{ __('Edit My Copy') }}</x-ui.button>
            @if ($copy->hasGalleryUpdate() && ! $copy->is_published)
                <form method="POST" action="{{ route('recipes.gallery.refresh', $copy) }}" onsubmit="return confirm({{ Illuminate\Support\Js::from(__('Replace :recipe with this reviewed gallery version?', ['recipe' => $copy->name])) }})">
                    @csrf
                    <x-ui.button type="submit" variant="primary">{{ __('Update Private Copy') }}</x-ui.button>
                </form>
            @endif
        </x-slot:buttons>
    </x-layouts.partials.heading>

    <x-ui.alert class="mt-6 p-4" tone="warning">
        <p class="font-semibold">{{ __('Review every changed command') }}</p>
        <p class="mt-1">{{ __('The left side is your encrypted private snapshot. The right side is the contributor’s current gallery version. No script is executed from this page.') }}</p>
    </x-ui.alert>

    <x-ui.insights id="recipe-comparison-insights" class="mt-6" :summary="__('Change summary')">
        <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.stat class="ui-card" :label="__('Script')" :value="$comparison['script_changed'] ? __('Changed') : __('Unchanged')" />
            <x-ui.stat class="ui-card" :label="__('Name')" :value="$comparison['name_changed'] ? __('Changed') : __('Unchanged')" />
            <x-ui.stat class="ui-card" :label="__('Description')" :value="$comparison['description_changed'] ? __('Changed') : __('Unchanged')" />
            <x-ui.stat class="ui-card" :label="__('Contributor')" :value="$recipe->user->name" />
        </dl>
    </x-ui.insights>

    <div class="ui-card mt-6 divide-y divide-primary overflow-hidden" aria-label="{{ __('Gallery comparison metadata') }}">
        @foreach ([
            ['label' => __('Name'), 'copy' => $copy->name, 'gallery' => $recipe->name],
            ['label' => __('Description'), 'copy' => $copy->description ?: __('No description'), 'gallery' => $recipe->description ?: __('No description'), 'long' => true],
            ['label' => __('Lines'), 'copy' => $comparison['current_lines'], 'gallery' => $comparison['gallery_lines']],
            ['label' => __('Revision'), 'copy' => $copy->source_revision_at?->format('Y-m-d H:i:s T') ?? __('Unknown'), 'gallery' => $recipe->gallery_revision_at?->format('Y-m-d H:i:s T') ?? __('Unknown')],
        ] as $comparisonRow)
            <section data-gallery-comparison-field class="p-4 sm:p-5">
                <h2 class="text-xs font-bold uppercase tracking-wide text-secondary">{{ $comparisonRow['label'] }}</h2>
                <dl class="mt-3 grid gap-4 sm:grid-cols-2">
                    <div @class(['rounded-lg bg-secondary p-3' => $comparisonRow['long'] ?? false])>
                        <dt class="text-xs font-semibold text-secondary">{{ __('My private copy') }}</dt>
                        <dd @class(['mt-2 text-primary', 'whitespace-pre-wrap break-words' => $comparisonRow['long'] ?? false])>{{ $comparisonRow['copy'] }}</dd>
                    </div>
                    <div @class(['rounded-lg bg-secondary p-3' => $comparisonRow['long'] ?? false])>
                        <dt class="text-xs font-semibold text-secondary">{{ __('Current gallery version') }}</dt>
                        <dd @class(['mt-2 text-primary', 'whitespace-pre-wrap break-words' => $comparisonRow['long'] ?? false])>{{ $comparisonRow['gallery'] }}</dd>
                    </div>
                </dl>
            </section>
        @endforeach
    </div>

    <div class="mt-6 grid gap-4 xl:grid-cols-2">
        <x-ui.card class="min-w-0 p-5" aria-labelledby="private-script-heading">
            <div class="flex items-center justify-between gap-3">
                <h2 id="private-script-heading" class="text-lg font-bold text-primary">{{ __('My private copy') }}</h2>
                <span class="text-xs text-secondary">{{ trans_choice(':count line|:count lines', $comparison['current_lines'], ['count' => $comparison['current_lines']]) }}</span>
            </div>
            <pre class="mt-3 overflow-x-auto rounded-lg bg-gray-950 p-4 text-sm text-gray-100"><code>{{ $copy->script }}</code></pre>
        </x-ui.card>
        <x-ui.card class="min-w-0 p-5" aria-labelledby="gallery-script-heading">
            <div class="flex items-center justify-between gap-3">
                <h2 id="gallery-script-heading" class="text-lg font-bold text-primary">{{ __('Current gallery version') }}</h2>
                <span class="text-xs text-secondary">{{ trans_choice(':count line|:count lines', $comparison['gallery_lines'], ['count' => $comparison['gallery_lines']]) }}</span>
            </div>
            <pre class="mt-3 overflow-x-auto rounded-lg bg-gray-950 p-4 text-sm text-gray-100"><code>{{ $recipe->script }}</code></pre>
        </x-ui.card>
    </div>
</x-layouts.app>
