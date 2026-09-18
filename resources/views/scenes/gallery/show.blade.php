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
                    <x-ui.button type="submit" variant="secondary">{{ __('Remove Saved') }}</x-ui.button>
                </form>
            @else
                <form method="POST" action="{{ route('gallery.favorite.store', $recipe) }}">
                    @csrf
                    <x-ui.button type="submit" variant="secondary">{{ __('Save Recipe') }}</x-ui.button>
                </form>
            @endif
            @if ($installedRecipe)
                <x-ui.button href="{{ route('recipes.edit', $installedRecipe) }}" variant="secondary">{{ __('View My Copy') }}</x-ui.button>
                <x-ui.button href="{{ route('gallery.compare', ['recipe' => $recipe, 'copy' => $installedRecipe]) }}" variant="secondary">{{ __('Compare Scripts') }}</x-ui.button>
                @if ($installedRecipe->hasGalleryUpdate() && ! $installedRecipe->is_published)
                    <form method="POST" action="{{ route('recipes.gallery.refresh', $installedRecipe) }}" onsubmit="return confirm({{ Illuminate\Support\Js::from(__('Replace :recipe with this reviewed gallery version?', ['recipe' => $installedRecipe->name])) }})">
                        @csrf
                        <x-ui.button type="submit" variant="primary">{{ __('Update My Copy') }}</x-ui.button>
                    </form>
                @endif
            @else
                <form method="POST" action="{{ route('gallery.install', $recipe) }}">
                    @csrf
                    <x-ui.button type="submit" variant="primary">{{ __('Add to My Recipes') }}</x-ui.button>
                </form>
            @endif
        </x-slot:buttons>
    </x-layouts.partials.heading>

    @if (session('status'))
        <x-ui.alert class="my-4" tone="success" role="status">{{ session('status') }}</x-ui.alert>
    @endif

    <x-ui.insights id="recipe-details-insights" class="mt-6" :summary="__('Recipe details')">
        <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.stat class="ui-card" :label="__('Category')" :value="str($recipe->category)->title()" />
            <x-ui.stat class="ui-card" :label="__('Contributor')" :value="$recipe->user->name" />
            <x-ui.stat class="ui-card" :label="__('Installs')" :value="$recipe->install_count" />
            <x-ui.stat class="ui-card" :label="__('Verified rating')" :value="$recipe->ratings_count ? __(':score / 5 from :count', ['score' => number_format((float) $recipe->ratings_avg_rating, 1), 'count' => trans_choice(':count rating|:count ratings', $recipe->ratings_count, ['count' => $recipe->ratings_count])]) : __('Not rated yet')" />
        </dl>
    </x-ui.insights>

    @if ($installedRecipe)
        <div @class([
            'ui-alert mt-6 p-4',
            'ui-alert--warning' => $installedRecipe->hasGalleryUpdate(),
            'ui-alert--success' => ! $installedRecipe->hasGalleryUpdate(),
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

    <x-ui.card class="mt-6 p-5 sm:p-6" aria-labelledby="gallery-rating-heading">
        <h2 id="gallery-rating-heading" class="text-lg font-bold text-primary">{{ __('Rate this recipe') }}</h2>
        @if ($canRate)
            <p class="mt-1 text-sm text-secondary">{{ __('Ratings are limited to people who installed the recipe. You can change or remove yours at any time.') }}</p>
            <div class="mt-4 flex flex-wrap items-end gap-3">
                <form method="POST" action="{{ route('gallery.rating.store', $recipe) }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <div>
                        <label for="rating" class="block text-xs font-semibold uppercase text-secondary">{{ __('Your rating') }}</label>
                        <select id="rating" name="rating" class="input secondary mt-2 rounded-lg" required>
                            <option value="">{{ __('Choose a score') }}</option>
                            @foreach ([5, 4, 3, 2, 1] as $score)
                                <option value="{{ $score }}" @selected((int) old('rating', $currentRating?->rating) === $score)>
                                    {{ trans_choice(':count star|:count stars', $score, ['count' => $score]) }}
                                </option>
                            @endforeach
                        </select>
                        <x-forms.errors name="rating" />
                    </div>
                    <x-ui.button type="submit" variant="primary">{{ $currentRating ? __('Update Rating') : __('Save Rating') }}</x-ui.button>
                </form>
                @if ($currentRating)
                    <form method="POST" action="{{ route('gallery.rating.destroy', $recipe) }}">
                        @csrf
                        @method('DELETE')
                        <x-ui.button type="submit" variant="secondary">{{ __('Remove Rating') }}</x-ui.button>
                    </form>
                @endif
            </div>
        @elseif ((int) $recipe->user_id === (int) auth()->id())
            <p class="mt-1 text-sm text-secondary">{{ __('Contributors cannot rate their own recipes.') }}</p>
        @else
            <p class="mt-1 text-sm text-secondary">{{ __('Add this recipe to your account before rating it.') }}</p>
        @endif
    </x-ui.card>

    <x-ui.card class="mt-6 p-5 sm:p-6" aria-labelledby="gallery-report-heading">
        @if ((int) $recipe->user_id === (int) auth()->id())
            @php($reportTotal = $reportCounts->sum())
            <h2 id="gallery-report-heading" class="text-lg font-bold text-primary">{{ __('Community reports') }}</h2>
            <p class="mt-1 text-sm text-secondary">
                {{ __('Reporter identities are private. Use this anonymous feedback to investigate and improve your published recipe.') }}
            </p>
            <a href="{{ route('gallery.reports.index') }}" class="mt-2 inline-block text-sm font-medium text-ternary underline">{{ __('Open all community feedback') }}</a>

            @if ($recentReports->isNotEmpty())
                @if ($reportTotal > 0)
                    <div class="mt-4 flex flex-wrap gap-2">
                        @foreach (\App\Models\RecipeReport::REASONS as $reason)
                            @if ($reportCounts->has($reason))
                                <x-ui.badge tone="danger">
                                    {{ str($reason)->headline() }}: {{ $reportCounts->get($reason) }}
                                </x-ui.badge>
                            @endif
                        @endforeach
                    </div>
                @else
                    <p class="mt-4 text-sm text-green-700">{{ __('All recent community reports have been resolved.') }}</p>
                @endif
                <div class="mt-4 space-y-3">
                    @foreach ($recentReports as $report)
                        <article class="ui-card bg-secondary p-4">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm font-semibold text-primary">{{ str($report->reason)->headline() }}</span>
                                    <x-ui.badge :tone="$report->resolved_at === null ? 'danger' : 'success'">{{ $report->resolved_at === null ? __('Needs review') : __('Resolved') }}</x-ui.badge>
                                </div>
                                <span class="text-xs text-secondary">{{ $report->created_at->diffForHumans() }}</span>
                            </div>
                            <p class="mt-2 whitespace-pre-line text-sm text-secondary">{{ $report->details ?: __('No additional details were provided.') }}</p>
                            @if ($report->resolved_at && $report->resolution_note)
                                <div class="ui-alert ui-alert--success mt-3 p-3">
                                    <p class="text-xs font-semibold uppercase">{{ __('Resolution note') }}</p>
                                    <p class="mt-1 whitespace-pre-line text-sm">{{ $report->resolution_note }}</p>
                                </div>
                            @endif
                            @if ($report->resolved_at === null)
                                <form method="POST" action="{{ route('gallery.reports.resolve', [$recipe, $report]) }}" class="mt-3 space-y-3">
                                    @csrf
                                    @method('PATCH')
                                    <div>
                                        <label for="resolution_note_{{ $report->id }}" class="block text-xs font-semibold uppercase text-secondary">{{ __('Resolution note (optional)') }}</label>
                                        <textarea id="resolution_note_{{ $report->id }}" name="resolution_note" rows="2" maxlength="1000" class="input secondary mt-2 w-full rounded-lg" placeholder="{{ __('Briefly explain what was addressed.') }}"></textarea>
                                    </div>
                                    <x-ui.button type="submit" variant="secondary">{{ __('Mark Resolved') }}</x-ui.button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('gallery.reports.resolution-note.update', [$recipe, $report]) }}" class="mt-3 space-y-3">
                                    @csrf
                                    @method('PATCH')
                                    <div>
                                        <label for="edit_resolution_note_{{ $report->id }}" class="block text-xs font-semibold uppercase text-secondary">{{ __('Resolution note') }}</label>
                                        <textarea id="edit_resolution_note_{{ $report->id }}" name="resolution_note" rows="2" maxlength="1000" class="input secondary mt-2 w-full rounded-lg" placeholder="{{ __('Briefly explain what was addressed.') }}">{{ $report->resolution_note }}</textarea>
                                        <p class="mt-1 text-xs text-secondary">{{ __('Leave empty to clear the note without reopening the report.') }}</p>
                                        <x-forms.errors name="resolution_note" />
                                    </div>
                                    <x-ui.button type="submit" variant="secondary">{{ $report->resolution_note ? __('Update Resolution Note') : __('Add Resolution Note') }}</x-ui.button>
                                </form>
                                <form method="POST" action="{{ route('gallery.reports.reopen', [$recipe, $report]) }}" class="mt-3">
                                    @csrf
                                    @method('PATCH')
                                    <x-ui.button type="submit" variant="secondary">{{ __('Reopen Report') }}</x-ui.button>
                                </form>
                            @endif
                        </article>
                    @endforeach
                </div>
            @else
                <p class="mt-4 text-sm text-secondary">{{ __('No community reports have been submitted for this recipe.') }}</p>
            @endif
        @else
            <h2 id="gallery-report-heading" class="text-lg font-bold text-primary">{{ __('Report a recipe issue') }}</h2>
            <p class="mt-1 text-sm text-secondary">
                {{ __('Tell the contributor about unsafe, broken, outdated, or misleading content. Your identity is not shown to them.') }}
            </p>
            @if ($currentReport)
                <p @class([
                    'ui-alert mt-3 p-3',
                    'ui-alert--danger' => $currentReport->resolved_at === null,
                    'ui-alert--success' => $currentReport->resolved_at !== null,
                ])>
                    {{ $currentReport->resolved_at === null
                        ? __('You reported this recipe as :reason. You can update or withdraw your report.', ['reason' => str($currentReport->reason)->headline()])
                        : __('The contributor marked your :reason report as resolved. Updating it will reopen it.', ['reason' => str($currentReport->reason)->headline()]) }}
                </p>
                @if ($currentReport->resolved_at && $currentReport->resolution_note)
                    <div class="ui-alert ui-alert--success mt-3 p-3 text-sm">
                        <p class="font-semibold">{{ __('Contributor resolution note') }}</p>
                        <p class="mt-1 whitespace-pre-line">{{ $currentReport->resolution_note }}</p>
                    </div>
                @endif
            @endif
            <form method="POST" action="{{ route('gallery.report.store', $recipe) }}" class="mt-4 space-y-4">
                @csrf
                <div>
                    <label for="reason" class="block text-xs font-semibold uppercase text-secondary">{{ __('Issue type') }}</label>
                    <select id="reason" name="reason" class="input secondary mt-2 w-full rounded-lg sm:max-w-xs" required>
                        <option value="">{{ __('Choose an issue') }}</option>
                        @foreach (\App\Models\RecipeReport::REASONS as $reason)
                            <option value="{{ $reason }}" @selected(old('reason', $currentReport?->reason) === $reason)>{{ str($reason)->headline() }}</option>
                        @endforeach
                    </select>
                    <x-forms.errors name="reason" />
                </div>
                <div>
                    <label for="details" class="block text-xs font-semibold uppercase text-secondary">{{ __('Details (optional)') }}</label>
                    <textarea id="details" name="details" rows="4" maxlength="1000" class="input secondary mt-2 w-full rounded-lg" placeholder="{{ __('Explain what the contributor should review.') }}">{{ old('details', $currentReport?->details) }}</textarea>
                    <x-forms.errors name="details" />
                </div>
                <x-ui.button type="submit" variant="primary">{{ $currentReport ? __('Update Report') : __('Submit Report') }}</x-ui.button>
            </form>
            @if ($currentReport)
                <form
                    method="POST"
                    action="{{ route('gallery.report.destroy', $recipe) }}"
                    class="mt-3"
                    onsubmit="return confirm({{ Illuminate\Support\Js::from(__('Withdraw your report for :recipe? This cannot be undone.', ['recipe' => $recipe->name])) }})"
                >
                    @csrf
                    @method('DELETE')
                    <x-ui.button type="submit" variant="danger">{{ __('Withdraw Report') }}</x-ui.button>
                </form>
            @endif
        @endif
    </x-ui.card>

    <x-ui.card class="mt-6 p-5 sm:p-6" aria-labelledby="gallery-script-heading">
        <x-ui.alert tone="warning" class="p-3">
            {{ __('This community script runs as root. Read every command and verify package sources, downloads, and destructive operations before using it.') }}
        </x-ui.alert>
        <h2 id="gallery-script-heading" class="mt-5 text-lg font-bold text-primary">{{ __('Bash script') }}</h2>
        <pre class="mt-3 overflow-x-auto rounded-lg bg-gray-950 p-4 text-sm text-gray-100"><code>{{ $recipe->script }}</code></pre>
    </x-ui.card>

    <p class="mt-4 text-xs text-secondary">
        {{ __('Published :date. Adding this recipe creates a private snapshot you can review and edit independently.', ['date' => $recipe->published_at->diffForHumans()]) }}
    </p>
</x-layouts.app>
