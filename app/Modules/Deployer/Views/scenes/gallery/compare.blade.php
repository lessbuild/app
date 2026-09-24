<x-layouts.app>
    @php
        $galleryComparePageUrl = request()->fullUrlWithoutQuery('dialog');
        $recipeEditDialogId = 'gallery-compare-recipe-edit-dialog';
        $recipeEditOpen = request()->query('dialog') === 'edit-recipe';
        $recipeEditUrl = (string) \Illuminate\Support\Uri::of($galleryComparePageUrl)->withQuery(['dialog' => 'edit-recipe']);
    @endphp

    <x-layouts.partials.breadcrumbs
        :route="route('gallery.show', $recipe)"
        :title="__('Back to gallery recipe')"
    />

    <x-signal.ui.page-header
        :title="__('Review gallery changes')"
        :description="$recipe->name"
    >
        <x-slot:actions>
            <x-signal.ui.button href="{{ $recipeEditUrl }}" data-modal-trigger="{{ $recipeEditDialogId }}" aria-controls="{{ $recipeEditDialogId }}" aria-expanded="{{ $recipeEditOpen ? 'true' : 'false' }}" variant="secondary">{{ __('Edit My Copy') }}</x-signal.ui.button>
            @if ($copy->hasGalleryUpdate() && ! $copy->is_published)
                <form method="POST" action="{{ route('recipes.gallery.refresh', $copy) }}" onsubmit="return confirm({{ Illuminate\Support\Js::from(__('Replace :recipe with this reviewed gallery version?', ['recipe' => $copy->name])) }})">
                    @csrf
                    <x-signal.ui.button type="submit" variant="primary">{{ __('Update Private Copy') }}</x-signal.ui.button>
                </form>
            @endif
        </x-slot:actions>
    </x-signal.ui.page-header>

    <x-signal.ui.alert class="mt-6 p-4" tone="warning">
        <p class="font-semibold">{{ __('Review every changed command') }}</p>
        <p class="mt-1">{{ __('The left side is your encrypted private snapshot. The right side is the contributor’s current gallery version. No script is executed from this page.') }}</p>
    </x-signal.ui.alert>

    <x-signal.ui.insights id="recipe-comparison-insights" class="mt-6" :summary="__('Change summary')">
        <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-signal.ui.stat class="ui-card" :label="__('Script')" :value="$comparison['script_changed'] ? __('Changed') : __('Unchanged')" />
            <x-signal.ui.stat class="ui-card" :label="__('Name')" :value="$comparison['name_changed'] ? __('Changed') : __('Unchanged')" />
            <x-signal.ui.stat class="ui-card" :label="__('Description')" :value="$comparison['description_changed'] ? __('Changed') : __('Unchanged')" />
            <x-signal.ui.stat class="ui-card" :label="__('Contributor')" :value="$recipe->user->name" />
        </dl>
    </x-signal.ui.insights>

    <div class="ui-card mt-6 divide-y divide-line overflow-hidden" aria-label="{{ __('Gallery comparison metadata') }}">
        @foreach ([
            ['label' => __('Name'), 'copy' => $copy->name, 'gallery' => $recipe->name],
            ['label' => __('Description'), 'copy' => $copy->description ?: __('No description'), 'gallery' => $recipe->description ?: __('No description'), 'long' => true],
            ['label' => __('Lines'), 'copy' => $comparison['current_lines'], 'gallery' => $comparison['gallery_lines']],
            ['label' => __('Revision'), 'copy' => $copy->source_revision_at?->format('Y-m-d H:i:s T') ?? __('Unknown'), 'gallery' => $recipe->gallery_revision_at?->format('Y-m-d H:i:s T') ?? __('Unknown')],
        ] as $comparisonRow)
            <section data-gallery-comparison-field class="p-4 sm:p-5">
                <h2 class="text-xs font-bold uppercase tracking-wide text-muted">{{ $comparisonRow['label'] }}</h2>
                <dl class="mt-3 grid gap-4 sm:grid-cols-2">
                    <div @class(['rounded-card bg-surface-muted p-3' => $comparisonRow['long'] ?? false])>
                        <dt class="text-xs font-semibold text-muted">{{ __('My private copy') }}</dt>
                        <dd @class(['mt-2 text-ink', 'whitespace-pre-wrap break-words' => $comparisonRow['long'] ?? false])>{{ $comparisonRow['copy'] }}</dd>
                    </div>
                    <div @class(['rounded-card bg-surface-muted p-3' => $comparisonRow['long'] ?? false])>
                        <dt class="text-xs font-semibold text-muted">{{ __('Current gallery version') }}</dt>
                        <dd @class(['mt-2 text-ink', 'whitespace-pre-wrap break-words' => $comparisonRow['long'] ?? false])>{{ $comparisonRow['gallery'] }}</dd>
                    </div>
                </dl>
            </section>
        @endforeach
    </div>

    <div class="mt-6 grid gap-4 xl:grid-cols-2">
        <x-signal.ui.card class="min-w-0 p-5" aria-labelledby="private-script-heading">
            <div class="flex items-center justify-between gap-3">
                <h2 id="private-script-heading" class="text-lg font-bold text-ink">{{ __('My private copy') }}</h2>
                <span class="text-xs text-muted">{{ trans_choice(':count line|:count lines', $comparison['current_lines'], ['count' => $comparison['current_lines']]) }}</span>
            </div>
            <pre class="ui-console mt-3 overflow-x-auto p-4 text-sm leading-6"><code>{{ $copy->script }}</code></pre>
        </x-signal.ui.card>
        <x-signal.ui.card class="min-w-0 p-5" aria-labelledby="gallery-script-heading">
            <div class="flex items-center justify-between gap-3">
                <h2 id="gallery-script-heading" class="text-lg font-bold text-ink">{{ __('Current gallery version') }}</h2>
                <span class="text-xs text-muted">{{ trans_choice(':count line|:count lines', $comparison['gallery_lines'], ['count' => $comparison['gallery_lines']]) }}</span>
            </div>
            <pre class="ui-console mt-3 overflow-x-auto p-4 text-sm leading-6"><code>{{ $recipe->script }}</code></pre>
        </x-signal.ui.card>
    </div>

    <x-scenes.recipes.edit-dialog
        :id="$recipeEditDialogId"
        :recipe="$copy"
        :open="$recipeEditOpen"
        :cancel-url="$galleryComparePageUrl"
        field-prefix="gallery-compare-recipe-edit-"
    />
</x-layouts.app>
