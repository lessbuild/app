<x-layouts.app>
    <x-layouts.partials.breadcrumbs :route="route('gallery.index')" :title="__('Back to gallery')" />

    <x-layouts.partials.heading
        :title="$recipe->name"
        :description="$recipe->description"
    >
        <x-slot:buttons>
            @if ($currentFavorite)
                <form method="POST" action="{{ route('gallery.favorite.destroy', $recipe) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="button secondary">{{ __('Remove Saved') }}</button>
                </form>
            @else
                <form method="POST" action="{{ route('gallery.favorite.store', $recipe) }}">
                    @csrf
                    <button type="submit" class="button secondary">{{ __('Save Recipe') }}</button>
                </form>
            @endif
            @if ($installedRecipe)
                <a href="{{ route('recipes.edit', $installedRecipe) }}" class="button secondary">{{ __('View My Copy') }}</a>
                <a href="{{ route('gallery.compare', ['recipe' => $recipe, 'copy' => $installedRecipe]) }}" class="button secondary">{{ __('Compare Scripts') }}</a>
                @if ($installedRecipe->hasGalleryUpdate() && ! $installedRecipe->is_published)
                    <form method="POST" action="{{ route('recipes.gallery.refresh', $installedRecipe) }}" onsubmit="return confirm('{{ __('Replace your private copy with this reviewed gallery version?') }}')">
                        @csrf
                        <button type="submit" class="button primary">{{ __('Update My Copy') }}</button>
                    </form>
                @endif
            @else
                <form method="POST" action="{{ route('gallery.install', $recipe) }}">
                    @csrf
                    <button type="submit" class="button primary">{{ __('Add to My Recipes') }}</button>
                </form>
            @endif
        </x-slot:buttons>
    </x-layouts.partials.heading>

    @if (session('status'))
        <div class="my-4 rounded border border-green-300 bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</div>
    @endif

    <dl class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Category') }}</dt>
            <dd class="mt-1 font-semibold text-primary">{{ str($recipe->category)->title() }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Contributor') }}</dt>
            <dd class="mt-1 font-semibold text-primary">{{ $recipe->user->name }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Installs') }}</dt>
            <dd class="mt-1 font-semibold text-primary">{{ $recipe->install_count }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Verified rating') }}</dt>
            <dd class="mt-1 font-semibold text-primary">
                {{ $recipe->ratings_count
                    ? __(':score / 5 from :count', ['score' => number_format((float) $recipe->ratings_avg_rating, 1), 'count' => trans_choice(':count rating|:count ratings', $recipe->ratings_count, ['count' => $recipe->ratings_count])])
                    : __('Not rated yet') }}
            </dd>
        </div>
    </dl>

    @if ($installedRecipe)
        <div @class([
            'mt-6 rounded border p-4 text-sm',
            'border-yellow-300 bg-yellow-50 text-yellow-800' => $installedRecipe->hasGalleryUpdate(),
            'border-green-300 bg-green-50 text-green-800' => ! $installedRecipe->hasGalleryUpdate(),
        ])>
            @if ($installedRecipe->hasGalleryUpdate())
                <p class="font-semibold">{{ __('A newer gallery version is available') }}</p>
                <p class="mt-1">
                    {{ $installedRecipe->is_published
                        ? __('Your copy is published. Unpublish it before refreshing so upstream changes cannot be redistributed automatically.')
                        : __('Compare the scripts below, then update your private copy when you are ready.') }}
                </p>
            @else
                <p class="font-semibold">{{ __('Installed in your recipes') }}</p>
                <p class="mt-1">{{ __('Your private snapshot matches the current gallery revision.') }}</p>
            @endif
        </div>
    @endif

    <section class="mt-6 rounded-lg border border-primary bg-primary p-5" aria-labelledby="gallery-rating-heading">
        <h2 id="gallery-rating-heading" class="text-lg font-bold text-primary">{{ __('Rate this recipe') }}</h2>
        @if ($canRate)
            <p class="mt-1 text-sm text-secondary">{{ __('Ratings are limited to people who installed the recipe. You can change or remove yours at any time.') }}</p>
            <div class="mt-4 flex flex-wrap items-end gap-3">
                <form method="POST" action="{{ route('gallery.rating.store', $recipe) }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <div>
                        <label for="rating" class="block text-xs font-semibold uppercase text-secondary">{{ __('Your rating') }}</label>
                        <select id="rating" name="rating" class="input secondary mt-1 rounded" required>
                            <option value="">{{ __('Choose a score') }}</option>
                            @foreach ([5, 4, 3, 2, 1] as $score)
                                <option value="{{ $score }}" @selected((int) old('rating', $currentRating?->rating) === $score)>
                                    {{ trans_choice(':count star|:count stars', $score, ['count' => $score]) }}
                                </option>
                            @endforeach
                        </select>
                        <x-forms.errors name="rating" />
                    </div>
                    <button type="submit" class="button primary">{{ $currentRating ? __('Update Rating') : __('Save Rating') }}</button>
                </form>
                @if ($currentRating)
                    <form method="POST" action="{{ route('gallery.rating.destroy', $recipe) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="button secondary">{{ __('Remove Rating') }}</button>
                    </form>
                @endif
            </div>
        @elseif ((int) $recipe->user_id === (int) auth()->id())
            <p class="mt-1 text-sm text-secondary">{{ __('Contributors cannot rate their own recipes.') }}</p>
        @else
            <p class="mt-1 text-sm text-secondary">{{ __('Add this recipe to your account before rating it.') }}</p>
        @endif
    </section>

    <section class="mt-6 rounded-lg border border-primary bg-primary p-5" aria-labelledby="gallery-script-heading">
        <div class="rounded border border-yellow-300 bg-yellow-50 p-3 text-sm text-yellow-800">
            {{ __('This community script runs as root. Read every command and verify package sources, downloads, and destructive operations before using it.') }}
        </div>
        <h2 id="gallery-script-heading" class="mt-5 text-lg font-bold text-primary">{{ __('Bash script') }}</h2>
        <pre class="mt-3 overflow-x-auto rounded bg-gray-950 p-4 text-sm text-gray-100"><code>{{ $recipe->script }}</code></pre>
    </section>

    <p class="mt-4 text-xs text-secondary">
        {{ __('Published :date. Adding this recipe creates a private snapshot you can review and edit independently.', ['date' => $recipe->published_at->diffForHumans()]) }}
    </p>
</x-layouts.app>
