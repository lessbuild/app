@php($changes = ['created' => __('Created'), 'edited' => __('Edited'), 'installed' => __('Installed from the gallery'), 'refreshed' => __('Updated from the gallery'), 'duplicated' => __('Copied')])

<x-signal.layouts.account :account="$account" :title="$recipe->name" :description="$recipe->description ?? __('A recipe that runs on new servers.')">
    @include('recipes._nav')
    @if (session('status'))
        <x-signal.ui.alert tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>
    @endif

    @if ($recipe->hasGalleryUpdate())
        <x-signal.ui.panel as="section" class="space-y-3 border-warning p-6">
            <h2 class="text-lg font-extrabold text-ink">{{ __('The gallery has a newer version') }}</h2>
            <p class="text-sm text-muted">{{ __('Updating replaces this recipe’s name, description and script. Your current version stays in the history.') }}</p>
            <x-signal.ui.disclosure :title="__('See the gallery version')">
                <x-signal.ui.code-block :code="$recipe->source?->script ?? ''" class="max-h-96 overflow-auto text-xs" />
            </x-signal.ui.disclosure>
            @if ($canUpdate)
                <form method="POST" action="{{ route('account.recipes.refresh', $recipe->id) }}">@csrf<x-signal.ui.button type="submit" variant="primary">{{ __('Update from the gallery') }}</x-signal.ui.button></form>
            @endif
        </x-signal.ui.panel>
    @endif

    <x-signal.ui.settings-section :title="__('Gallery')" :description="__('Publishing shares this recipe’s script with every account. Owners and admins decide.')">
        <div class="flex flex-wrap items-center justify-between gap-3 p-4 text-sm sm:p-6">
            <p>
                @if ($recipe->is_published)
                    <x-signal.ui.badge tone="info">{{ __('Published') }}</x-signal.ui.badge>
                    <span class="text-muted">{{ trans_choice(':count install|:count installs', $recipe->install_count) }}@if ($openReports > 0) · <a href="{{ route('account.recipes.reports') }}" class="text-danger hover:underline">{{ trans_choice(':count open report|:count open reports', $openReports) }}</a>@endif · <a href="{{ route('recipes.gallery.show', $recipe->id) }}" class="text-primary hover:underline">{{ __('View in the gallery') }}</a></span>
                @elseif ($recipe->source_recipe_id !== null)
                    <span class="text-muted">{{ __('Installed from the gallery') }}@if ($recipe->source) · <a href="{{ route('recipes.gallery.show', $recipe->source->id) }}" class="text-primary hover:underline">{{ $recipe->source->name }}</a>@endif</span>
                @else
                    <span class="text-muted">{{ __('Only your account sees it.') }}</span>
                @endif
            </p>
            @if ($canPublish)
                <form method="POST" action="{{ route('account.recipes.publication', $recipe->id) }}">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="published" value="{{ $recipe->is_published ? '0' : '1' }}">
                    <x-signal.ui.button type="submit" :variant="$recipe->is_published ? 'quiet' : 'secondary'" size="sm">{{ $recipe->is_published ? __('Take out of the gallery') : __('Publish to the gallery') }}</x-signal.ui.button>
                </form>
            @endif
        </div>
    </x-signal.ui.settings-section>

    <x-signal.ui.settings-section :title="__('Script')" :description="$canUpdate ? __('Saving keeps a revision. Servers created earlier keep the version they ran.') : __('Runs as root at the end of a new server’s provisioning.')">
        @if ($canUpdate)
            <form method="POST" action="{{ route('account.recipes.update', $recipe->id) }}" class="grid items-start gap-5 p-4 sm:grid-cols-2 sm:p-6">
                @csrf
                @method('PUT')
                @include('recipes._fields', ['recipe' => $recipe])
                <div class="flex flex-wrap gap-2 sm:col-span-2">
                    <x-signal.ui.button type="submit" variant="primary">{{ __('Save recipe') }}</x-signal.ui.button>
                </div>
            </form>
            <div class="flex flex-wrap gap-2 border-t border-line p-4 sm:px-6">
                <form method="POST" action="{{ route('account.recipes.duplicate', $recipe->id) }}">@csrf<x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Make a copy') }}</x-signal.ui.button></form>
                <x-signal.ui.button variant="danger" size="sm" data-modal-trigger="delete-recipe">{{ __('Delete recipe') }}</x-signal.ui.button>
                <x-signal.overlays.delete-confirmation id="delete-recipe" :route="route('account.recipes.destroy', $recipe->id)" :title="__('Delete :name?', ['name' => $recipe->name])" :description="__('Its history goes too. Servers keep the copy they ran, and copies other accounts installed stay.')" :submit-label="__('Delete recipe')" />
            </div>
        @else
            <div class="p-4 sm:p-6"><x-signal.ui.code-block :code="$recipe->script" class="max-h-[32rem] overflow-auto text-xs" /></div>
        @endif
    </x-signal.ui.settings-section>

    <x-signal.ui.settings-section :title="__('History')" :description="__('The latest :count saved versions.', ['count' => \App\Models\Recipe::KEEP_REVISIONS])">
        <ul class="divide-y divide-line">
            @foreach ($recipe->revisions as $revision)
                <li class="px-4 py-3 text-sm sm:px-6">
                    <x-signal.ui.disclosure :title="($changes[$revision->change] ?? $revision->change).' · '.($revision->user->name ?? __('someone')).' · '.$revision->created_at->diffForHumans()">
                        <p class="mb-2 text-xs text-muted">{{ $revision->name }}@if ($revision->description) · {{ $revision->description }}@endif</p>
                        <x-signal.ui.code-block :code="$revision->script" class="max-h-80 overflow-auto text-xs" />
                    </x-signal.ui.disclosure>
                </li>
            @endforeach
        </ul>
    </x-signal.ui.settings-section>
</x-signal.layouts.account>
