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
            <x-ui.button href="{{ route('gallery.index') }}" variant="secondary">{{ __('Browse Gallery') }}</x-ui.button>
            <x-ui.button href="{{ route('recipes.export', array_filter($filters, fn ($value) => $value !== null)) }}" variant="secondary">{{ __('Export CSV') }}</x-ui.button>
            <x-ui.button
                :href="$recipeCreateUrl"
                data-modal-trigger="recipe-create-dialog"
                aria-controls="recipe-create-dialog"
                aria-expanded="{{ $recipeCreateOpen ? 'true' : 'false' }}"
                variant="primary"
            >
                <svg class="h-4 w-4" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#plus-circle"></use></svg>
                {{ __('Add Recipe') }}
            </x-ui.button>
        </x-slot:buttons>
    </x-layouts.partials.heading>

    @if (session('status'))
        <x-ui.alert class="my-4" tone="success" role="status">{{ session('status') }}</x-ui.alert>
    @endif

    @php
        $recipeFilterCount = collect($filters)->filter(fn ($value) => filled($value))->count();
    @endphp

    <x-ui.filter-panel
        id="recipe-filters"
        class="mt-8"
        :open="$recipeFilterCount > 0"
        :summary="$recipeFilterCount > 0 ? trans_choice(':count active filter|:count active filters', $recipeFilterCount, ['count' => $recipeFilterCount]) : null"
    >
        <form method="GET" action="{{ route('recipes.index') }}">
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="search" class="block text-xs font-semibold uppercase tracking-wide text-secondary">{{ __('Search') }}</label>
                    <input id="search" name="search" type="search" maxlength="100" value="{{ $filters['search'] }}" placeholder="{{ __('Name or description') }}" class="input secondary mt-2 w-full rounded-lg">
                </div>
                <div>
                    <label for="usage" class="block text-xs font-semibold uppercase tracking-wide text-secondary">{{ __('Usage') }}</label>
                    <select id="usage" name="usage" class="input secondary mt-2 w-full rounded-lg">
                        <option value="">{{ __('All usage states') }}</option>
                        @foreach ($usages as $usage)
                            <option value="{{ $usage }}" @selected($filters['usage'] === $usage)>{{ str($usage)->replace('_', ' ')->title() }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
                <x-ui.button type="submit" variant="primary">{{ __('Apply filters') }}</x-ui.button>
                @if (array_filter($filters, fn ($value) => $value !== null))
                    <x-ui.button href="{{ route('recipes.index') }}" variant="ghost">{{ __('Clear filters') }}</x-ui.button>
                @endif
            </div>
        </form>
    </x-ui.filter-panel>

    <x-ui.insights
        id="recipe-insights"
        class="mt-6"
        :open="false"
        :summary="trans_choice(':count matching recipe|:count matching recipes', $metrics['total'], ['count' => $metrics['total']])"
    >
        <dl class="ui-insight-grid grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
            <x-ui.stat class="ui-card" :label="__('Matching recipes')" :value="$metrics['total']" :description="__('Recipes in this filtered view.')" />
            <x-ui.stat class="ui-card" :label="__('In use')" :value="$metrics['in_use']" :description="__('Matching recipes assigned to servers.')" />
            <x-ui.stat class="ui-card" :label="__('Unused')" :value="$metrics['unused']" :description="__('Matching recipes without assignments.')" />
            <x-ui.stat class="ui-card" :label="__('Server assignments')" :value="$metrics['assignments']" :description="__('All matching recipe-to-server links.')" />
            <x-ui.stat class="ui-card" :label="__('Covered servers')" :value="$metrics['servers']" :description="__('Distinct servers using matching recipes.')" />
            <x-ui.stat class="ui-card" :label="__('Latest update')" :value="$metrics['latest_at']?->diffForHumans() ?? __('No matching recipe')" :description="__('Most recently updated matching recipe.')" />
        </dl>
    </x-ui.insights>

    @if ($recipes->isEmpty())
        <div class="mx-auto max-w-3xl">
            <x-lists.empty
                :title="array_filter($filters, fn ($value) => $value !== null) ? __('No recipes match these filters') : __('You have no recipes')"
                :description="array_filter($filters, fn ($value) => $value !== null) ? __('Try changing or clearing the selected filters.') : __('Create a recipe to automate custom setup on new servers.')"
            >
                <x-slot:button>
                    @if (array_filter($filters, fn ($value) => $value !== null))
                        <x-ui.button href="{{ route('recipes.index') }}" variant="secondary">{{ __('Clear filters') }}</x-ui.button>
                    @else
                        <x-ui.button
                            :href="$recipeCreateUrl"
                            data-modal-trigger="recipe-create-dialog"
                            aria-controls="recipe-create-dialog"
                            aria-expanded="{{ $recipeCreateOpen ? 'true' : 'false' }}"
                            variant="primary"
                        >{{ __('Add Recipe') }}</x-ui.button>
                    @endif
                </x-slot:button>
            </x-lists.empty>
        </div>
    @else
        <div class="ui-card mt-6 divide-y divide-primary overflow-hidden" aria-label="{{ __('Recipe inventory') }}">
            @foreach ($recipes as $recipe)
                <article data-recipe-card class="p-4 sm:p-5">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0">
                            <a class="font-bold text-primary hover:underline" href="{{ route('recipes.show', $recipe) }}">{{ $recipe->name }}</a>
                            <p class="mt-1 max-w-3xl text-sm text-secondary">{{ $recipe->description ?: __('No description') }}</p>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                @if ($recipe->is_published)
                                    <x-ui.badge tone="accent"><a href="{{ route('gallery.show', $recipe) }}">{{ __('Published') }}</a></x-ui.badge>
                                @endif
                                @if ($recipe->source_recipe_id)
                                    @if ($recipe->source && $recipe->hasGalleryUpdate())
                                        <x-ui.badge tone="warning"><a href="{{ route('gallery.show', $recipe->source) }}">{{ __('Gallery update available') }}</a></x-ui.badge>
                                    @elseif ($recipe->source)
                                        <x-ui.badge tone="neutral"><a href="{{ route('gallery.show', $recipe->source) }}">{{ __('Gallery copy current') }}</a></x-ui.badge>
                                    @else
                                        <x-ui.badge tone="neutral">{{ __('Gallery source unavailable') }}</x-ui.badge>
                                    @endif
                                @endif
                            </div>
                        </div>
                        <dl class="flex shrink-0 gap-6 text-sm">
                            <div>
                                <dt class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Used by') }}</dt>
                                <dd class="mt-1 text-primary">{{ trans_choice(':count server|:count servers', $recipe->servers_count, ['count' => $recipe->servers_count]) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Updated') }}</dt>
                                <dd class="mt-1 text-primary">{{ $recipe->updated_at->diffForHumans() }}</dd>
                            </div>
                        </dl>
                    </div>
                    <div class="mt-4 flex flex-wrap justify-start gap-2 sm:justify-end">
                        <x-ui.button href="{{ route('recipes.show', $recipe) }}" variant="secondary">{{ __('View') }}</x-ui.button>
                        @php
                            $recipeEditUrl = route('recipes.index', [...$recipeIndexQuery, 'dialog' => 'edit-recipe-'.$recipe->id]);
                            $recipeEditDialogIdForRow = 'recipe-edit-dialog-'.$recipe->id;
                            $recipeEditContentUrl = route('recipes.edit', ['recipe' => $recipe, 'dialog' => 'edit-recipe-'.$recipe->id, 'fragment' => 1, 'return_to' => request()->fullUrlWithoutQuery('dialog')]);
                            $recipeDialogRecipe = $editingRecipe?->is($recipe) ? $editingRecipe : $recipe;
                        @endphp
                        <x-ui.button
                            :href="$recipeEditUrl"
                            data-modal-trigger="{{ $recipeEditDialogIdForRow }}"
                            data-modal-content-url="{{ $recipeEditContentUrl }}"
                            aria-controls="{{ $recipeEditDialogIdForRow }}"
                            aria-expanded="{{ $editingRecipe?->id === $recipe->id ? 'true' : 'false' }}"
                            variant="secondary"
                        >{{ __('Edit') }}</x-ui.button>
                        <form method="POST" action="{{ route('recipes.duplicate', $recipe) }}">
                            @csrf
                            <x-ui.button type="submit" variant="secondary">{{ __('Duplicate') }}</x-ui.button>
                        </form>
                        <form method="POST" action="{{ route('recipes.destroy', $recipe) }}" onsubmit="return confirm({{ Illuminate\Support\Js::from(__('Delete :recipe? This cannot be undone.', ['recipe' => $recipe->name])) }})">
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="submit" variant="danger">{{ __('Delete') }}</x-ui.button>
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
        </div>
    @endif
    <x-scenes.recipes.create-dialog :open="$recipeCreateOpen" />

</x-layouts.app>
