<x-layouts.app>
    <x-layouts.partials.heading
        :title="__('Community Recipe Gallery')"
        :description="__('Discover reusable provisioning scripts shared by other operators.')"
    >
        <x-slot:buttons>
            <a href="{{ route('recipes.create') }}" class="button primary">{{ __('Publish a Recipe') }}</a>
        </x-slot:buttons>
    </x-layouts.partials.heading>

    @if (session('status'))
        <div class="my-4 rounded border border-green-300 bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</div>
    @endif

    <div class="mt-6 rounded border border-yellow-300 bg-yellow-50 p-4 text-sm text-yellow-800">
        <p class="font-semibold">{{ __('Review community scripts before using them') }}</p>
        <p class="mt-1">{{ __('Recipes run as root during provisioning. Inspect the full script and adapt it to your environment before assigning it to a server.') }}</p>
    </div>

    <form method="GET" action="{{ route('gallery.index') }}" class="mt-6 rounded-lg border border-primary bg-primary p-4">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div>
                <label for="search" class="block text-xs font-semibold uppercase text-secondary">{{ __('Search') }}</label>
                <input id="search" name="search" type="search" maxlength="100" value="{{ $filters['search'] }}" placeholder="{{ __('Name or description') }}" class="input secondary mt-1 w-full rounded">
            </div>
            <div>
                <label for="category" class="block text-xs font-semibold uppercase text-secondary">{{ __('Category') }}</label>
                <select id="category" name="category" class="input secondary mt-1 w-full rounded">
                    <option value="">{{ __('All categories') }}</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category }}" @selected($filters['category'] === $category)>{{ str($category)->title() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="scope" class="block text-xs font-semibold uppercase text-secondary">{{ __('Collection') }}</label>
                <select id="scope" name="scope" class="input secondary mt-1 w-full rounded">
                    <option value="all" @selected($filters['scope'] === 'all')>{{ __('All recipes') }}</option>
                    <option value="favorites" @selected($filters['scope'] === 'favorites')>{{ __('Saved by me') }}</option>
                    <option value="installed" @selected($filters['scope'] === 'installed')>{{ __('Installed by me') }}</option>
                    <option value="updates" @selected($filters['scope'] === 'updates')>{{ __('Updates available') }}</option>
                    <option value="mine" @selected($filters['scope'] === 'mine')>{{ __('Published by me') }}</option>
                </select>
            </div>
            <div>
                <label for="sort" class="block text-xs font-semibold uppercase text-secondary">{{ __('Sort') }}</label>
                <select id="sort" name="sort" class="input secondary mt-1 w-full rounded">
                    <option value="recent" @selected($filters['sort'] === 'recent')>{{ __('Recently published') }}</option>
                    <option value="popular" @selected($filters['sort'] === 'popular')>{{ __('Most installed') }}</option>
                    <option value="top_rated" @selected($filters['sort'] === 'top_rated')>{{ __('Top rated') }}</option>
                </select>
            </div>
        </div>
        <div class="mt-4 flex gap-3">
            <button type="submit" class="button primary">{{ __('Apply filters') }}</button>
            @if ($filters['search'] || $filters['category'] || $filters['scope'] !== 'all' || $filters['sort'] !== 'recent')
                <a href="{{ route('gallery.index') }}" class="button secondary">{{ __('Clear filters') }}</a>
            @endif
        </div>
    </form>

    <dl class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Published recipes') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['published'] }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Community installs') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['installs'] }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Contributors') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['authors'] }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Verified ratings') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['ratings'] }}</dd>
        </div>
    </dl>

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
                    $updateAvailable = $installedRecipe?->hasGalleryUpdate($recipe) ?? false;
                @endphp
                <article class="rounded-lg border border-primary bg-primary p-5">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <span class="rounded-full bg-blue-100 px-2 py-1 text-xs font-semibold text-blue-700">{{ str($recipe->category)->title() }}</span>
                            @if ((int) $recipe->user_id === (int) auth()->id())
                                <span class="ml-1 rounded-full bg-purple-100 px-2 py-1 text-xs font-semibold text-purple-700">{{ __('Published by you') }}</span>
                            @endif
                            @if ($favorite)
                                <span class="ml-1 rounded-full bg-pink-100 px-2 py-1 text-xs font-semibold text-pink-700">{{ __('Saved') }}</span>
                            @endif
                            @if ($updateAvailable)
                                <span class="ml-1 rounded-full bg-yellow-100 px-2 py-1 text-xs font-semibold text-yellow-800">{{ __('Update available') }}</span>
                            @elseif ($installedRecipe)
                                <span class="ml-1 rounded-full bg-green-100 px-2 py-1 text-xs font-semibold text-green-700">{{ __('Installed') }}</span>
                            @endif
                            <h2 class="mt-3 text-lg font-bold text-primary">
                                <a href="{{ route('gallery.show', $recipe) }}" class="text-ternary">{{ $recipe->name }}</a>
                            </h2>
                        </div>
                        <div class="text-right text-xs text-secondary">
                            <span class="block">{{ trans_choice(':count install|:count installs', $recipe->install_count, ['count' => $recipe->install_count]) }}</span>
                            <span class="mt-1 block">
                                {{ $recipe->ratings_count
                                    ? __(':score / 5 (:count)', ['score' => number_format((float) $recipe->ratings_avg_rating, 1), 'count' => $recipe->ratings_count])
                                    : __('Not rated') }}
                            </span>
                        </div>
                    </div>
                    <p class="mt-2 text-sm text-secondary">{{ $recipe->description }}</p>
                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 text-xs text-secondary">
                        <span>{{ __('By :author', ['author' => $recipe->user->name]) }}</span>
                        <div class="flex items-center gap-2">
                            @if ($favorite)
                                <form method="POST" action="{{ route('gallery.favorite.destroy', $recipe) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="button secondary">{{ __('Remove saved') }}</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('gallery.favorite.store', $recipe) }}">
                                    @csrf
                                    <button type="submit" class="button secondary">{{ __('Save recipe') }}</button>
                                </form>
                            @endif
                            <a href="{{ route('gallery.show', $recipe) }}" class="button tertiary">{{ __('Inspect script') }}</a>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
        <div class="mt-6">{{ $recipes->links() }}</div>
    @endif
</x-layouts.app>
