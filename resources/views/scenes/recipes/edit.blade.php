<x-layouts.app>
    <x-layouts.partials.breadcrumbs :route="route('recipes.index')" :title="__('Back to recipes')" />

    @if (session('status'))
        <div class="my-4 rounded border border-green-300 bg-green-50 p-3 text-sm text-green-700">
            {{ session('status') }}
        </div>
    @endif

    @if ($recipe->source)
        <div @class([
            'my-4 rounded border p-4 text-sm',
            'border-yellow-300 bg-yellow-50 text-yellow-800' => $recipe->hasGalleryUpdate(),
            'border-blue-300 bg-blue-50 text-blue-800' => ! $recipe->hasGalleryUpdate(),
        ])>
            <p class="font-semibold">
                {{ __('Imported from :recipe by :author', ['recipe' => $recipe->source->name, 'author' => $recipe->source->user->name]) }}
            </p>
            @if ($recipe->hasGalleryUpdate())
                <p class="mt-1">{{ __('A newer gallery revision is available. Inspect it before replacing your private snapshot.') }}</p>
                <div class="mt-3 flex flex-wrap gap-3">
                    <a href="{{ route('gallery.compare', ['recipe' => $recipe->source, 'copy' => $recipe]) }}" class="button secondary">{{ __('Review Changes') }}</a>
                    @if (! $recipe->is_published)
                        <form method="POST" action="{{ route('recipes.gallery.refresh', $recipe) }}" onsubmit="return confirm({{ Illuminate\Support\Js::from(__('Replace :recipe with the reviewed gallery version?', ['recipe' => $recipe->name])) }})">
                            @csrf
                            <button type="submit" class="button primary">{{ __('Update Private Copy') }}</button>
                        </form>
                    @else
                        <span class="text-xs">{{ __('Unpublish this copy before refreshing it.') }}</span>
                    @endif
                </div>
            @else
                <p class="mt-1">{{ __('Your private snapshot matches the current gallery revision.') }}</p>
                <a href="{{ route('gallery.show', $recipe->source) }}" class="mt-3 inline-block font-medium underline">{{ __('View gallery source') }}</a>
            @endif
        </div>
    @elseif ($recipe->source_recipe_id)
        <div class="my-4 rounded border border-primary bg-secondary p-4 text-sm text-secondary">
            <p class="font-semibold text-primary">{{ __('Gallery source unavailable') }}</p>
            <p class="mt-1">{{ __('The contributor removed or unpublished the source. Your encrypted private snapshot is unchanged and remains editable.') }}</p>
        </div>
    @endif

    <form method="POST" action="{{ route('recipes.update', $recipe) }}">
        @csrf
        @method('PATCH')
        <x-forms.section
            :title="__('Edit Recipe')"
            :description="__('Changes apply when this recipe is used for a new server.')"
        >
            <x-scenes.recipes._form :recipe="$recipe" />

            <x-slot:footer>
                <div class="px-4 py-3 bg-tertiary text-right sm:px-6">
                    <button class="button primary" type="submit">{{ __('Save Recipe') }}</button>
                </div>
            </x-slot:footer>
        </x-forms.section>
    </form>
</x-layouts.app>
