<x-signal.layouts.account :account="$account" :title="__('Recipes')" :description="__('Scripts that run on new servers at the end of provisioning. Choose them when you create a server, or install one from the gallery.')">
    @include('recipes._nav')
    @if (session('status'))
        <x-signal.ui.alert tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>
    @endif

    @if ($recipes->isEmpty())
        <x-signal.ui.empty-state icon="code" :title="__('No recipes yet')" :description="__('Write one below, or install one from the gallery.')" />
    @else
        <x-signal.ui.card class="overflow-hidden">
            <ul class="divide-y divide-line" aria-label="{{ __('Recipes') }}">
                @foreach ($recipes as $recipe)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                        <div class="min-w-0">
                            <a href="{{ route('account.recipes.show', $recipe->id) }}" class="font-extrabold text-ink hover:underline">{{ $recipe->name }}</a>
                            <p class="mt-0.5 text-xs text-muted">{{ $recipe->category->label() }}@if ($recipe->description) · {{ \Illuminate\Support\Str::limit($recipe->description, 100) }}@endif</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if ($recipe->is_published)<x-signal.ui.badge tone="info">{{ trans_choice('In the gallery · :count install|In the gallery · :count installs', $recipe->install_count) }}</x-signal.ui.badge>@endif
                            @if ($recipe->source_recipe_id !== null)<x-signal.ui.badge tone="neutral">{{ __('From the gallery') }}</x-signal.ui.badge>@endif
                            @if ($recipe->hasGalleryUpdate())<x-signal.ui.badge tone="warning">{{ __('Update available') }}</x-signal.ui.badge>@endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-signal.ui.card>
    @endif

    @if ($canCreate)
        <x-slot:actions>
            <x-signal.ui.button :href="request()->fullUrlWithQuery(['dialog' => 'new-recipe'])" variant="primary" data-modal-trigger="new-recipe">{{ __('New recipe') }}</x-signal.ui.button>
        </x-slot:actions>
        <x-signal.overlays.form-modal id="new-recipe" :title="__('New recipe')" :description="__('Each save keeps a revision, so you can see what changed and who changed it.')" :action="route('account.recipes.store')" :submit="__('Save recipe')" form-class="grid items-start gap-5 sm:grid-cols-2">
            @include('recipes._fields')
        </x-signal.overlays.form-modal>
    @endif
</x-signal.layouts.account>
