<x-signal.layouts.account :account="$account" :title="__('Recipe gallery')" :description="__('Recipes other accounts have published. Read a script before you install it: it runs as root on your servers.')">
    @include('recipes._nav')

    <form method="GET" action="{{ route('recipes.gallery') }}" class="flex flex-wrap items-end gap-3">
        <x-signal.ui.input-field name="q" :label="__('Search')" :value="$filters['q']" placeholder="redis" maxlength="100" :restore="false" />
        <x-signal.ui.select-field name="category" :label="__('Category')">
            <option value="">{{ __('All') }}</option>
            @foreach ($categories as $category)
                <option value="{{ $category->value }}" @selected($filters['category'] === $category->value)>{{ $category->label() }}</option>
            @endforeach
        </x-signal.ui.select-field>
        <x-signal.ui.select-field name="sort" :label="__('Sort by')">
            @foreach (['popular' => __('Most installed'), 'rating' => __('Best rated'), 'newest' => __('Newest')] as $value => $label)
                <option value="{{ $value }}" @selected(($filters['sort'] ?: 'popular') === $value)>{{ $label }}</option>
            @endforeach
        </x-signal.ui.select-field>
        <x-signal.ui.checkbox name="favorites" value="1" :checked="$filters['favorites']" :restore="false">{{ __('Favourites only') }}</x-signal.ui.checkbox>
        <x-signal.ui.button type="submit" variant="secondary">{{ __('Show') }}</x-signal.ui.button>
    </form>

    @if ($recipes->isEmpty())
        <x-signal.ui.empty-state icon="code" :title="__('No recipes found')" :description="__('Try another search or category.')" />
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($recipes as $recipe)
                <x-signal.ui.card class="flex flex-col gap-2 p-5">
                    <a href="{{ route('recipes.gallery.show', $recipe->id) }}" class="font-extrabold text-ink hover:underline">{{ $recipe->name }}</a>
                    <p class="text-xs text-muted">{{ $recipe->category->label() }} · {{ __('by :account', ['account' => $recipe->account->name]) }}</p>
                    @if ($recipe->description)<p class="text-sm">{{ \Illuminate\Support\Str::limit($recipe->description, 140) }}</p>@endif
                    <p class="mt-auto text-xs text-muted">
                        {{ trans_choice(':count install|:count installs', $recipe->install_count) }}
                        · @if ($recipe->ratings_count > 0){{ __(':average★ from :count', ['average' => number_format((float) $recipe->ratings_avg_rating, 1), 'count' => $recipe->ratings_count]) }}@else{{ __('Not rated yet') }}@endif
                        @if ($recipe->installed) · <span class="text-success">{{ __('Installed') }}</span>@endif
                        @if ($recipe->favorited) · {{ __('Favourite') }}@endif
                    </p>
                </x-signal.ui.card>
            @endforeach
        </div>
        {{ $recipes->links() }}
    @endif
</x-signal.layouts.account>
