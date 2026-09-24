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

    <x-signal.ui.page-header
        :title="__('Community Recipe Gallery')"
        :description="__('Discover reusable provisioning scripts shared by other operators.')"
    >
        <x-slot:actions>
            <x-signal.ui.button href="{{ route('recipes.index') }}" variant="secondary">{{ __('My recipes') }}</x-signal.ui.button>
            <x-signal.ui.button href="{{ route('gallery.reports.mine') }}" variant="secondary">{{ __('My Reports') }}</x-signal.ui.button>
            <x-signal.ui.button href="{{ route('gallery.reports.index') }}" variant="secondary">{{ __('Feedback Inbox') }}</x-signal.ui.button>
            <x-signal.ui.button
                href="{{ $publishRecipeDialogUrl }}"
                data-modal-trigger="gallery-publish-recipe-dialog"
                aria-controls="gallery-publish-recipe-dialog"
                aria-expanded="{{ $publishRecipeDialogOpen ? 'true' : 'false' }}"
                variant="primary"
            >
                {{ __('Publish a Recipe') }}
            </x-signal.ui.button>
        </x-slot:actions>
    </x-signal.ui.page-header>

    <x-signal.ui.local-nav class="mt-6" :label="__('Gallery sections')">
        <a href="#gallery-safety" class="ui-local-nav__link">{{ __('Safety') }}</a>
        <a href="#gallery-insights" class="ui-local-nav__link">{{ __('Insights') }}</a>
        <a href="#gallery-inventory" class="ui-local-nav__link">{{ __('Recipes') }}</a>
    </x-signal.ui.local-nav>

    <x-scenes.gallery.publish-dialog
        :cancel-url="$galleryIndexUrl"
        :open="$publishRecipeDialogOpen"
    />

    @if (session('status'))
        <x-signal.ui.alert class="my-4" tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>
    @endif

    <x-signal.ui.alert id="gallery-safety" class="ui-panel mt-6 scroll-mt-24 border-l-4 p-4" tone="warning">
        <p class="font-semibold">{{ __('Review community scripts before using them') }}</p>
        <p class="mt-1">{{ __('Recipes run as root during provisioning. Inspect the full script and adapt it to your environment before assigning it to a server.') }}</p>
    </x-signal.ui.alert>

    @php
        $galleryFilterCount = collect($filters)->filter(fn ($value, $key) => filled($value)
            && ($key === 'search' || $key === 'category' || $value !== ($key === 'scope' ? 'all' : 'recent')))->count();
    @endphp

    <x-signal.ui.filter-panel
        id="gallery-filters"
        class="mt-6"
        :open="$galleryFilterCount > 0"
        :summary="$galleryFilterCount > 0 ? trans_choice(':count active filter|:count active filters', $galleryFilterCount, ['count' => $galleryFilterCount]) : null"
    >
        <form method="GET" action="{{ route('gallery.index') }}">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div>
                <label for="search" class="ui-label">{{ __('Search') }}</label>
                <x-signal.ui.input id="search" name="search" type="search" maxlength="100" value="{{ $filters['search'] }}" placeholder="{{ __('Name or description') }}" class="ui-input mt-2" :restore="false" />
            </div>
            <div>
                <label for="category" class="ui-label">{{ __('Category') }}</label>
                <x-signal.ui.select id="category" name="category" class="ui-input mt-2">
                    <option value="">{{ __('All categories') }}</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category }}" @selected($filters['category'] === $category)>{{ str($category)->title() }}</option>
                    @endforeach
                </x-signal.ui.select>
            </div>
            <div>
                <label for="scope" class="ui-label">{{ __('Collection') }}</label>
                <x-signal.ui.select id="scope" name="scope" class="ui-input mt-2">
                    <option value="all" @selected($filters['scope'] === 'all')>{{ __('All recipes') }}</option>
                    <option value="favorites" @selected($filters['scope'] === 'favorites')>{{ __('Saved by me') }}</option>
                    <option value="reported" @selected($filters['scope'] === 'reported')>{{ __('Reported by me') }}</option>
                    <option value="reports_open" @selected($filters['scope'] === 'reports_open')>{{ __('My reports needing review') }}</option>
                    <option value="reports_resolved" @selected($filters['scope'] === 'reports_resolved')>{{ __('My resolved reports') }}</option>
                    <option value="installed" @selected($filters['scope'] === 'installed')>{{ __('Installed by me') }}</option>
                    <option value="updates" @selected($filters['scope'] === 'updates')>{{ __('Updates available') }}</option>
                    <option value="mine" @selected($filters['scope'] === 'mine')>{{ __('Published by me') }}</option>
                </x-signal.ui.select>
            </div>
            <div>
                <label for="sort" class="ui-label">{{ __('Sort') }}</label>
                <x-signal.ui.select id="sort" name="sort" class="ui-input mt-2">
                    <option value="recent" @selected($filters['sort'] === 'recent')>{{ __('Recently published') }}</option>
                    <option value="popular" @selected($filters['sort'] === 'popular')>{{ __('Most installed') }}</option>
                    <option value="top_rated" @selected($filters['sort'] === 'top_rated')>{{ __('Top rated') }}</option>
                </x-signal.ui.select>
            </div>
        </div>
        <div class="mt-4 flex gap-3">
            <x-signal.ui.button type="submit" variant="primary">{{ __('Apply filters') }}</x-signal.ui.button>
            @if ($filters['search'] || $filters['category'] || $filters['scope'] !== 'all' || $filters['sort'] !== 'recent')
                <x-signal.ui.button href="{{ route('gallery.index') }}" variant="ghost">{{ __('Clear filters') }}</x-signal.ui.button>
            @endif
        </div>
        </form>
    </x-signal.ui.filter-panel>

    <x-signal.ui.insights
        id="gallery-insights"
        class="mt-6 scroll-mt-24"
        :open="false"
        :summary="trans_choice(':count published recipe|:count published recipes', $metrics['published'], ['count' => $metrics['published']])"
    >
        <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-signal.ui.stat class="ui-card" :label="__('Published recipes')" :value="$metrics['published']" />
            <x-signal.ui.stat class="ui-card" :label="__('Community installs')" :value="$metrics['installs']" />
            <x-signal.ui.stat class="ui-card" :label="__('Contributors')" :value="$metrics['authors']" />
            <x-signal.ui.stat class="ui-card" :label="__('Verified ratings')" :value="$metrics['ratings']" />
        </dl>
    </x-signal.ui.insights>

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
                <x-signal.ui.card class="ui-card--interactive p-5 sm:p-6">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <x-signal.ui.badge tone="accent">{{ str($recipe->category)->title() }}</x-signal.ui.badge>
                            @if ((int) $recipe->user_id === (int) auth()->id())
                                <x-signal.ui.badge tone="neutral">{{ __('Published by you') }}</x-signal.ui.badge>
                            @endif
                            @if ($favorite)
                                <x-signal.ui.badge tone="success">{{ __('Saved') }}</x-signal.ui.badge>
                            @endif
                            @if ($report)
                                <x-signal.ui.badge tone="danger">{{ __('Reported by you: :reason', ['reason' => str($report->reason)->headline()]) }}</x-signal.ui.badge>
                                <x-signal.ui.badge :tone="$report->resolved_at === null ? 'warning' : 'success'">{{ $report->resolved_at === null ? __('Needs contributor review') : __('Resolved by contributor') }}</x-signal.ui.badge>
                            @endif
                            @if ($updateAvailable)
                                <x-signal.ui.badge tone="warning">{{ __('Update available') }}</x-signal.ui.badge>
                            @elseif ($installedRecipe)
                                <x-signal.ui.badge tone="success">{{ __('Installed') }}</x-signal.ui.badge>
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
                                    <x-signal.ui.button type="submit" variant="secondary">{{ __('Remove saved') }}</x-signal.ui.button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('gallery.favorite.store', $recipe) }}">
                                    @csrf
                                    <x-signal.ui.button type="submit" variant="secondary">{{ __('Save recipe') }}</x-signal.ui.button>
                                </form>
                            @endif
                            <x-signal.ui.button
                                href="{{ $inspectDialogUrl }}"
                                data-modal-trigger="{{ $inspectDialogId }}"
                                data-modal-content-url="{{ parse_url(route('gallery.script', $recipe), PHP_URL_PATH) }}"
                                aria-controls="{{ $inspectDialogId }}"
                                aria-expanded="{{ $inspectDialogOpen ? 'true' : 'false' }}"
                                variant="primary"
                            >
                                {{ __('Inspect script') }}
                            </x-signal.ui.button>
                        </div>
                    </div>
                </x-signal.ui.card>

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
