<x-layouts.app>
    @php
        $galleryQuery = array_filter($filters, fn ($value) => $value !== null);
        $publishRecipeDialogHasErrors = old('_recipe_publish_form') === '1'
            && $errors->hasAny(['name', 'description', 'script', 'is_published', 'category']);
        $publishRecipeDialogOpen = (request()->query('dialog') === 'publish-recipe' && ! session()->has('status'))
            || $publishRecipeDialogHasErrors;
        $publishRecipeDialogUrl = route('gallery.index', [...$galleryQuery, 'dialog' => 'publish-recipe']);
        $galleryIndexUrl = route('gallery.index', $galleryQuery);
    @endphp

    <x-layouts.partials.heading
        :title="__('Community Recipe Gallery')"
        :description="__('Discover reusable provisioning scripts shared by other operators.')"
    >
        <x-slot:buttons>
            <x-ui.button href="{{ route('recipes.index') }}" variant="secondary">{{ __('My recipes') }}</x-ui.button>
            <x-ui.button href="{{ route('gallery.reports.mine') }}" variant="secondary">{{ __('My Reports') }}</x-ui.button>
            <x-ui.button href="{{ route('gallery.reports.index') }}" variant="secondary">{{ __('Feedback Inbox') }}</x-ui.button>
            <x-ui.button
                href="{{ $publishRecipeDialogUrl }}"
                data-modal-trigger="gallery-publish-recipe-dialog"
                aria-controls="gallery-publish-recipe-dialog"
                aria-expanded="{{ $publishRecipeDialogOpen ? 'true' : 'false' }}"
                variant="primary"
            >
                {{ __('Publish a Recipe') }}
            </x-ui.button>
        </x-slot:buttons>
    </x-layouts.partials.heading>

    <x-ui.local-nav class="mt-6" :label="__('Gallery sections')">
        <a href="#gallery-safety" class="ui-local-nav__link">{{ __('Safety') }}</a>
        <a href="#gallery-insights" class="ui-local-nav__link">{{ __('Insights') }}</a>
        <a href="#gallery-inventory" class="ui-local-nav__link">{{ __('Recipes') }}</a>
    </x-ui.local-nav>

    <x-scenes.gallery.publish-dialog
        :cancel-url="$galleryIndexUrl"
        :open="$publishRecipeDialogOpen"
    />

    @if (session('status'))
        <x-ui.alert class="my-4" tone="success" role="status">{{ session('status') }}</x-ui.alert>
    @endif

    <x-ui.alert id="gallery-safety" class="ui-panel mt-6 scroll-mt-24 border-l-4 p-4" tone="warning">
        <p class="font-semibold">{{ __('Review community scripts before using them') }}</p>
        <p class="mt-1">{{ __('Recipes run as root during provisioning. Inspect the full script and adapt it to your environment before assigning it to a server.') }}</p>
    </x-ui.alert>

    @php
        $galleryFilterCount = collect($filters)->filter(fn ($value, $key) => filled($value)
            && ($key === 'search' || $key === 'category' || $value !== ($key === 'scope' ? 'all' : 'recent')))->count();
    @endphp

    <x-ui.filter-panel
        id="gallery-filters"
        class="mt-6"
        :open="$galleryFilterCount > 0"
        :summary="$galleryFilterCount > 0 ? trans_choice(':count active filter|:count active filters', $galleryFilterCount, ['count' => $galleryFilterCount]) : null"
    >
        <form method="GET" action="{{ route('gallery.index') }}">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div>
                <label for="search" class="ui-label">{{ __('Search') }}</label>
                <input id="search" name="search" type="search" maxlength="100" value="{{ $filters['search'] }}" placeholder="{{ __('Name or description') }}" class="ui-input mt-2">
            </div>
            <div>
                <label for="category" class="ui-label">{{ __('Category') }}</label>
                <select id="category" name="category" class="ui-input mt-2">
                    <option value="">{{ __('All categories') }}</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category }}" @selected($filters['category'] === $category)>{{ str($category)->title() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="scope" class="ui-label">{{ __('Collection') }}</label>
                <select id="scope" name="scope" class="ui-input mt-2">
                    <option value="all" @selected($filters['scope'] === 'all')>{{ __('All recipes') }}</option>
                    <option value="favorites" @selected($filters['scope'] === 'favorites')>{{ __('Saved by me') }}</option>
                    <option value="reported" @selected($filters['scope'] === 'reported')>{{ __('Reported by me') }}</option>
                    <option value="reports_open" @selected($filters['scope'] === 'reports_open')>{{ __('My reports needing review') }}</option>
                    <option value="reports_resolved" @selected($filters['scope'] === 'reports_resolved')>{{ __('My resolved reports') }}</option>
                    <option value="installed" @selected($filters['scope'] === 'installed')>{{ __('Installed by me') }}</option>
                    <option value="updates" @selected($filters['scope'] === 'updates')>{{ __('Updates available') }}</option>
                    <option value="mine" @selected($filters['scope'] === 'mine')>{{ __('Published by me') }}</option>
                </select>
            </div>
            <div>
                <label for="sort" class="ui-label">{{ __('Sort') }}</label>
                <select id="sort" name="sort" class="ui-input mt-2">
                    <option value="recent" @selected($filters['sort'] === 'recent')>{{ __('Recently published') }}</option>
                    <option value="popular" @selected($filters['sort'] === 'popular')>{{ __('Most installed') }}</option>
                    <option value="top_rated" @selected($filters['sort'] === 'top_rated')>{{ __('Top rated') }}</option>
                </select>
            </div>
        </div>
        <div class="mt-4 flex gap-3">
            <x-ui.button type="submit" variant="primary">{{ __('Apply filters') }}</x-ui.button>
            @if ($filters['search'] || $filters['category'] || $filters['scope'] !== 'all' || $filters['sort'] !== 'recent')
                <x-ui.button href="{{ route('gallery.index') }}" variant="ghost">{{ __('Clear filters') }}</x-ui.button>
            @endif
        </div>
        </form>
    </x-ui.filter-panel>

    <x-ui.insights
        id="gallery-insights"
        class="mt-6 scroll-mt-24"
        :open="false"
        :summary="trans_choice(':count published recipe|:count published recipes', $metrics['published'], ['count' => $metrics['published']])"
    >
        <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.stat class="ui-card" :label="__('Published recipes')" :value="$metrics['published']" />
            <x-ui.stat class="ui-card" :label="__('Community installs')" :value="$metrics['installs']" />
            <x-ui.stat class="ui-card" :label="__('Contributors')" :value="$metrics['authors']" />
            <x-ui.stat class="ui-card" :label="__('Verified ratings')" :value="$metrics['ratings']" />
        </dl>
    </x-ui.insights>

    <div id="gallery-inventory" data-gallery-section="inventory" class="scroll-mt-24">
    @if ($recipes->isEmpty())
        <div class="mx-auto mt-6 max-w-3xl">
            <x-lists.empty
                :title="__('No published recipes match these filters')"
                :description="__('Try another search or publish the first recipe in this category.')"
            />
        </div>
    @else
        <div class="mt-6 grid gap-4 lg:grid-cols-2">
            @foreach ($recipes as $recipe)
                @php
                    $installedRecipe = $recipe->installs->first();
                    $favorite = $recipe->favorites->first();
                    $report = $recipe->reports->first();
                    $updateAvailable = $installedRecipe?->hasGalleryUpdate($recipe) ?? false;
                    $inspectDialogId = 'gallery-inspect-script-'.$recipe->id;
                    $inspectDialogOpen = (int) $inspectRecipe?->id === (int) $recipe->id;
                    $inspectDialogUrl = route('gallery.index', [...$galleryQuery, 'dialog' => 'inspect-script-'.$recipe->id]);
                @endphp
                <x-ui.card class="ui-card--interactive p-5 sm:p-6">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <x-ui.badge tone="accent">{{ str($recipe->category)->title() }}</x-ui.badge>
                            @if ((int) $recipe->user_id === (int) auth()->id())
                                <x-ui.badge tone="neutral">{{ __('Published by you') }}</x-ui.badge>
                            @endif
                            @if ($favorite)
                                <x-ui.badge tone="success">{{ __('Saved') }}</x-ui.badge>
                            @endif
                            @if ($report)
                                <x-ui.badge tone="danger">{{ __('Reported by you: :reason', ['reason' => str($report->reason)->headline()]) }}</x-ui.badge>
                                <x-ui.badge :tone="$report->resolved_at === null ? 'warning' : 'success'">{{ $report->resolved_at === null ? __('Needs contributor review') : __('Resolved by contributor') }}</x-ui.badge>
                            @endif
                            @if ($updateAvailable)
                                <x-ui.badge tone="warning">{{ __('Update available') }}</x-ui.badge>
                            @elseif ($installedRecipe)
                                <x-ui.badge tone="success">{{ __('Installed') }}</x-ui.badge>
                            @endif
                            <h2 class="mt-3 text-lg font-bold text-ink">
                                <a href="{{ route('gallery.show', $recipe) }}" class="ui-link">{{ $recipe->name }}</a>
                            </h2>
                        </div>
                        <div class="text-right text-xs text-muted">
                            <span class="block">{{ trans_choice(':count install|:count installs', $recipe->install_count, ['count' => $recipe->install_count]) }}</span>
                            <span class="mt-1 block">
                                {{ $recipe->ratings_count
                                    ? __(':score / 5 (:count)', ['score' => number_format((float) $recipe->ratings_avg_rating, 1), 'count' => $recipe->ratings_count])
                                    : __('Not rated') }}
                            </span>
                        </div>
                    </div>
                    <p class="mt-2 text-sm text-muted">{{ $recipe->description }}</p>
                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 text-xs text-muted">
                        <span>{{ __('By :author', ['author' => $recipe->user->name]) }}</span>
                        <div class="flex items-center gap-2">
                            @if ($favorite)
                                <form method="POST" action="{{ route('gallery.favorite.destroy', $recipe) }}">
                                    @csrf
                                    @method('DELETE')
                                    <x-ui.button type="submit" variant="secondary">{{ __('Remove saved') }}</x-ui.button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('gallery.favorite.store', $recipe) }}">
                                    @csrf
                                    <x-ui.button type="submit" variant="secondary">{{ __('Save recipe') }}</x-ui.button>
                                </form>
                            @endif
                            <x-ui.button
                                href="{{ $inspectDialogUrl }}"
                                data-modal-trigger="{{ $inspectDialogId }}"
                                data-modal-content-url="{{ parse_url(route('gallery.script', $recipe), PHP_URL_PATH) }}"
                                aria-controls="{{ $inspectDialogId }}"
                                aria-expanded="{{ $inspectDialogOpen ? 'true' : 'false' }}"
                                variant="primary"
                            >
                                {{ __('Inspect script') }}
                            </x-ui.button>
                        </div>
                    </div>
                </x-ui.card>

                <x-scenes.gallery.inspect-dialog
                    :inspect-recipe="$inspectRecipe"
                    :open="$inspectDialogOpen"
                    :recipe="$recipe"
                />
            @endforeach
        </div>
        <div class="mt-6">{{ $recipes->links() }}</div>
    @endif
    </div>
</x-layouts.app>
