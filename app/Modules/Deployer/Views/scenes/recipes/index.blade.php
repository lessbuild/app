<x-layouts.app>
    @php
        $recipeIndexQuery = array_filter($filters, fn ($value) => $value !== null);
        if (request()->filled('page')) {
            $recipeIndexQuery['page'] = request()->query('page');
        }
        $recipeCreateOpen = request()->query('dialog') === 'create-recipe';
        $recipeCreateUrl = route('recipes.index', [...$recipeIndexQuery, 'dialog' => 'create-recipe']);
    @endphp

    <x-layouts.partials.heading
        :title="__('Provisioning Recipes')"
        :description="__('Create reusable Bash scripts for new servers.')"
    >
        <x-slot:buttons>
            <x-signal.ui.button href="{{ route('gallery.index') }}" variant="secondary">{{ __('Browse Gallery') }}</x-signal.ui.button>
            <x-signal.ui.button href="{{ route('recipes.export', array_filter($filters, fn ($value) => $value !== null)) }}" variant="secondary">{{ __('Export CSV') }}</x-signal.ui.button>
            <x-signal.ui.button
                :href="$recipeCreateUrl"
                data-modal-trigger="recipe-create-dialog"
                aria-controls="recipe-create-dialog"
                aria-expanded="{{ $recipeCreateOpen ? 'true' : 'false' }}"
                variant="primary"
            >
                <svg class="h-4 w-4" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#plus-circle"></use></svg>
                {{ __('Add Recipe') }}
            </x-signal.ui.button>
        </x-slot:buttons>
    </x-layouts.partials.heading>

    <x-signal.ui.local-nav class="mt-6" :label="__('Recipe sections')">
        <a href="#recipe-insights" class="ui-local-nav__link">{{ __('Insights') }}</a>
        <a href="#recipe-inventory" class="ui-local-nav__link">{{ __('Inventory') }}</a>
    </x-signal.ui.local-nav>

    @if (session('status'))
        <x-signal.ui.alert class="my-4" tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>
    @endif

    @php
        $recipeFilterCount = collect($filters)->filter(fn ($value) => filled($value))->count();
    @endphp

    <x-signal.ui.filter-panel
        id="recipe-filters"
        class="mt-8"
        :open="$recipeFilterCount > 0"
        :summary="$recipeFilterCount > 0 ? trans_choice(':count active filter|:count active filters', $recipeFilterCount, ['count' => $recipeFilterCount]) : null"
    >
        <form method="GET" action="{{ route('recipes.index') }}">
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="search" class="ui-label">{{ __('Search') }}</label>
                    <x-signal.ui.input id="search" name="search" type="search" maxlength="100" value="{{ $filters['search'] }}" placeholder="{{ __('Name or description') }}" class="ui-input mt-2" :restore="false" />
                </div>
                <div>
                    <label for="usage" class="ui-label">{{ __('Usage') }}</label>
                    <x-signal.ui.select id="usage" name="usage" class="ui-input mt-2">
                        <option value="">{{ __('All usage states') }}</option>
                        @foreach ($usages as $usage)
                            <option value="{{ $usage }}" @selected($filters['usage'] === $usage)>{{ str($usage)->replace('_', ' ')->title() }}</option>
                        @endforeach
                    </x-signal.ui.select>
                </div>
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
                <x-signal.ui.button type="submit" variant="primary">{{ __('Apply filters') }}</x-signal.ui.button>
                @if (array_filter($filters, fn ($value) => $value !== null))
                    <x-signal.ui.button href="{{ route('recipes.index') }}" variant="ghost">{{ __('Clear filters') }}</x-signal.ui.button>
                @endif
            </div>
        </form>
    </x-signal.ui.filter-panel>

    <x-signal.ui.insights
        id="recipe-insights"
        class="mt-6 scroll-mt-24"
        :open="false"
        :summary="trans_choice(':count matching recipe|:count matching recipes', $metrics['total'], ['count' => $metrics['total']])"
    >
        <dl class="ui-insight-grid grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
            <x-signal.ui.stat class="ui-card" :label="__('Matching recipes')" :value="$metrics['total']" :description="__('Recipes in this filtered view.')" />
            <x-signal.ui.stat class="ui-card" :label="__('In use')" :value="$metrics['in_use']" :description="__('Matching recipes assigned to servers.')" />
            <x-signal.ui.stat class="ui-card" :label="__('Unused')" :value="$metrics['unused']" :description="__('Matching recipes without assignments.')" />
            <x-signal.ui.stat class="ui-card" :label="__('Server assignments')" :value="$metrics['assignments']" :description="__('All matching recipe-to-server links.')" />
            <x-signal.ui.stat class="ui-card" :label="__('Covered servers')" :value="$metrics['servers']" :description="__('Distinct servers using matching recipes.')" />
            <x-signal.ui.stat class="ui-card" :label="__('Latest update')" :value="$metrics['latest_at']?->diffForHumans() ?? __('No matching recipe')" :description="__('Most recently updated matching recipe.')" />
        </dl>
    </x-signal.ui.insights>

    <div id="recipe-inventory" data-recipe-section="inventory" class="scroll-mt-24">
    @if ($recipes->isEmpty())
        <div class="mx-auto max-w-3xl">
            <x-lists.empty
                :title="array_filter($filters, fn ($value) => $value !== null) ? __('No recipes match these filters') : __('You have no recipes')"
                :description="array_filter($filters, fn ($value) => $value !== null) ? __('Try changing or clearing the selected filters.') : __('Create a recipe to automate custom setup on new servers.')"
            >
                <x-slot:button>
                    @if (array_filter($filters, fn ($value) => $value !== null))
                        <x-signal.ui.button href="{{ route('recipes.index') }}" variant="secondary">{{ __('Clear filters') }}</x-signal.ui.button>
                    @else
                        <x-signal.ui.button
                            :href="$recipeCreateUrl"
                            data-modal-trigger="recipe-create-dialog"
                            aria-controls="recipe-create-dialog"
                            aria-expanded="{{ $recipeCreateOpen ? 'true' : 'false' }}"
                            variant="primary"
                        >{{ __('Add Recipe') }}</x-signal.ui.button>
                    @endif
                </x-slot:button>
            </x-lists.empty>
        </div>
    @else
        <x-signal.ui.panel class="ui-panel mt-6 divide-y divide-line overflow-hidden" aria-label="{{ __('Recipe inventory') }}">
            @foreach ($recipes as $recipe)
                <article data-recipe-card class="p-4 transition-colors hover:bg-surface-muted sm:p-5">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0">
                            <a class="font-bold text-ink hover:underline" href="{{ route('recipes.show', $recipe) }}">{{ $recipe->name }}</a>
                            <p class="mt-1 max-w-3xl text-sm text-muted">{{ $recipe->description ?: __('No description') }}</p>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                @if ($recipe->is_published)
                                    <x-signal.ui.badge tone="accent"><a href="{{ route('gallery.show', $recipe) }}">{{ __('Published') }}</a></x-signal.ui.badge>
                                @endif
                                @if ($recipe->source_recipe_id)
                                    @if ($recipe->source && $recipe->hasGalleryUpdate())
                                        <x-signal.ui.badge tone="warning"><a href="{{ route('gallery.show', $recipe->source) }}">{{ __('Gallery update available') }}</a></x-signal.ui.badge>
                                    @elseif ($recipe->source)
                                        <x-signal.ui.badge tone="neutral"><a href="{{ route('gallery.show', $recipe->source) }}">{{ __('Gallery copy current') }}</a></x-signal.ui.badge>
                                    @else
                                        <x-signal.ui.badge tone="neutral">{{ __('Gallery source unavailable') }}</x-signal.ui.badge>
                                    @endif
                                @endif
                            </div>
                        </div>
                        <dl class="flex shrink-0 gap-6 text-sm">
                            <div>
                                <dt class="ui-eyebrow text-[0.65rem]">{{ __('Used by') }}</dt>
                                <dd class="mt-1 text-ink">{{ trans_choice(':count server|:count servers', $recipe->servers_count, ['count' => $recipe->servers_count]) }}</dd>
                            </div>
                            <div>
                                <dt class="ui-eyebrow text-[0.65rem]">{{ __('Updated') }}</dt>
                                <dd class="mt-1 text-ink">{{ $recipe->updated_at->diffForHumans() }}</dd>
                            </div>
                        </dl>
                    </div>
                    <div class="mt-4 flex flex-wrap justify-start gap-2 sm:justify-end">
                        <x-signal.ui.button href="{{ route('recipes.show', $recipe) }}" variant="secondary">{{ __('View') }}</x-signal.ui.button>
                        @php
                            $recipeEditUrl = route('recipes.index', [...$recipeIndexQuery, 'dialog' => 'edit-recipe-'.$recipe->id]);
                            $recipeEditDialogIdForRow = 'recipe-edit-dialog-'.$recipe->id;
                            $recipeEditContentUrl = route('recipes.edit', ['recipe' => $recipe, 'dialog' => 'edit-recipe-'.$recipe->id, 'fragment' => 1, 'return_to' => request()->fullUrlWithoutQuery('dialog')]);
                            $recipeDialogRecipe = $editingRecipe?->is($recipe) ? $editingRecipe : $recipe;
                        @endphp
                        <x-signal.ui.button
                            :href="$recipeEditUrl"
                            data-modal-trigger="{{ $recipeEditDialogIdForRow }}"
                            data-modal-content-url="{{ $recipeEditContentUrl }}"
                            aria-controls="{{ $recipeEditDialogIdForRow }}"
                            aria-expanded="{{ $editingRecipe?->id === $recipe->id ? 'true' : 'false' }}"
                            variant="secondary"
                        >{{ __('Edit') }}</x-signal.ui.button>
                        <form method="POST" action="{{ route('recipes.duplicate', $recipe) }}">
                            @csrf
                            <x-signal.ui.button type="submit" variant="secondary">{{ __('Duplicate') }}</x-signal.ui.button>
                        </form>
                        <form method="POST" action="{{ route('recipes.destroy', $recipe) }}" onsubmit="return confirm({{ Illuminate\Support\Js::from(__('Delete :recipe? This cannot be undone.', ['recipe' => $recipe->name])) }})">
                            @csrf
                            @method('DELETE')
                            <x-signal.ui.button type="submit" variant="danger">{{ __('Delete') }}</x-signal.ui.button>
                        </form>
                    </div>
                    <x-scenes.recipes.edit-dialog
                        :recipe="$recipeDialogRecipe"
                        :id="$recipeEditDialogIdForRow"
                        :open="$editingRecipe?->is($recipe) ?? false"
                        :cancel-url="route('recipes.index', $recipeIndexQuery)"
                        :content-url="$recipeEditContentUrl"
                        field-prefix="recipe-edit-"
                    />
                </article>
            @endforeach
            <div class="p-4">{{ $recipes->links() }}</div>
        </x-signal.ui.panel>
    @endif
    </div>
    <x-scenes.recipes.create-dialog :open="$recipeCreateOpen" />

</x-layouts.app>
