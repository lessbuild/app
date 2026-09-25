<x-layouts.app>
    <x-layouts.partials.breadcrumbs :route="route('recipes.index')" :title="__('Back to recipes')" />

    @if (session('status'))
        <x-signal.ui.alert tone="success" class="my-4">
            {{ session('status') }}
        </x-signal.ui.alert>
    @endif

    @if ($recipe->source)
        <x-signal.ui.alert :tone="$recipe->hasGalleryUpdate() ? 'warning' : 'info'" class="my-4 p-4">
            <p class="font-semibold">
                {{ __('Imported from :recipe by :author', ['recipe' => $recipe->source->name, 'author' => $recipe->source->user->name]) }}
            </p>
            @if ($recipe->hasGalleryUpdate())
                <p class="mt-1">{{ __('A newer gallery revision is available. Inspect it before replacing your private snapshot.') }}</p>
                <div class="mt-3 flex flex-wrap gap-3">
                    <x-signal.ui.button href="{{ route('gallery.compare', ['recipe' => $recipe->source, 'copy' => $recipe]) }}" variant="secondary">{{ __('Review Changes') }}</x-signal.ui.button>
                    @if (! $recipe->is_published)
                        <form method="POST" action="{{ route('recipes.gallery.refresh', $recipe) }}" onsubmit="return confirm({{ Illuminate\Support\Js::from(__('Replace :recipe with the reviewed gallery version?', ['recipe' => $recipe->name])) }})">
                            @csrf
                            <x-signal.ui.button type="submit" variant="primary">{{ __('Update Private Copy') }}</x-signal.ui.button>
                        </form>
                    @else
                        <span class="text-xs">{{ __('Unpublish this copy before refreshing it.') }}</span>
                    @endif
                </div>
            @else
                <p class="mt-1">{{ __('Your private snapshot matches the current gallery revision.') }}</p>
                <a href="{{ route('gallery.show', $recipe->source) }}" class="mt-3 inline-block font-medium underline">{{ __('View gallery source') }}</a>
            @endif
        </x-signal.ui.alert>
    @elseif ($recipe->source_recipe_id)
        <x-signal.ui.alert tone="info" class="my-4 p-4">
            <p class="font-semibold text-ink">{{ __('Gallery source unavailable') }}</p>
            <p class="mt-1">{{ __('The contributor removed or unpublished the source. Your encrypted private snapshot is unchanged and remains editable.') }}</p>
        </x-signal.ui.alert>
    @endif

    <x-scenes.recipes.edit-dialog :recipe="$recipe" :open="true" field-prefix="" />
</x-layouts.app>
