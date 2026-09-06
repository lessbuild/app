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
                    <form method="POST" action="{{ route('recipes.gallery.refresh', $installedRecipe) }}" onsubmit="return confirm({{ Illuminate\Support\Js::from(__('Replace :recipe with this reviewed gallery version?', ['recipe' => $installedRecipe->name])) }})">
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

    <section class="mt-6 rounded-lg border border-primary bg-primary p-5" aria-labelledby="gallery-report-heading">
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
                                <span class="rounded-full bg-red-100 px-3 py-1 text-xs font-semibold text-red-700">
                                    {{ str($reason)->headline() }}: {{ $reportCounts->get($reason) }}
                                </span>
                            @endif
                        @endforeach
                    </div>
                @else
                    <p class="mt-4 text-sm text-green-700">{{ __('All recent community reports have been resolved.') }}</p>
                @endif
                <div class="mt-4 space-y-3">
                    @foreach ($recentReports as $report)
                        <article class="rounded border border-primary bg-secondary p-4">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm font-semibold text-primary">{{ str($report->reason)->headline() }}</span>
                                    <span @class([
                                        'rounded-full px-2 py-0.5 text-xs font-semibold',
                                        'bg-red-100 text-red-700' => $report->resolved_at === null,
                                        'bg-green-100 text-green-700' => $report->resolved_at !== null,
                                    ])>{{ $report->resolved_at === null ? __('Needs review') : __('Resolved') }}</span>
                                </div>
                                <span class="text-xs text-secondary">{{ $report->created_at->diffForHumans() }}</span>
                            </div>
                            <p class="mt-2 whitespace-pre-line text-sm text-secondary">{{ $report->details ?: __('No additional details were provided.') }}</p>
                            @if ($report->resolved_at && $report->resolution_note)
                                <div class="mt-3 rounded border border-green-200 bg-green-50 p-3">
                                    <p class="text-xs font-semibold uppercase text-green-700">{{ __('Resolution note') }}</p>
                                    <p class="mt-1 whitespace-pre-line text-sm text-green-800">{{ $report->resolution_note }}</p>
                                </div>
                            @endif
                            @if ($report->resolved_at === null)
                                <form method="POST" action="{{ route('gallery.reports.resolve', [$recipe, $report]) }}" class="mt-3 space-y-3">
                                    @csrf
                                    @method('PATCH')
                                    <div>
                                        <label for="resolution_note_{{ $report->id }}" class="block text-xs font-semibold uppercase text-secondary">{{ __('Resolution note (optional)') }}</label>
                                        <textarea id="resolution_note_{{ $report->id }}" name="resolution_note" rows="2" maxlength="1000" class="input secondary mt-1 w-full rounded" placeholder="{{ __('Briefly explain what was addressed.') }}"></textarea>
                                    </div>
                                    <button type="submit" class="button secondary">{{ __('Mark Resolved') }}</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('gallery.reports.resolution-note.update', [$recipe, $report]) }}" class="mt-3 space-y-3">
                                    @csrf
                                    @method('PATCH')
                                    <div>
                                        <label for="edit_resolution_note_{{ $report->id }}" class="block text-xs font-semibold uppercase text-secondary">{{ __('Resolution note') }}</label>
                                        <textarea id="edit_resolution_note_{{ $report->id }}" name="resolution_note" rows="2" maxlength="1000" class="input secondary mt-1 w-full rounded" placeholder="{{ __('Briefly explain what was addressed.') }}">{{ $report->resolution_note }}</textarea>
                                        <p class="mt-1 text-xs text-secondary">{{ __('Leave empty to clear the note without reopening the report.') }}</p>
                                        <x-forms.errors name="resolution_note" />
                                    </div>
                                    <button type="submit" class="button secondary">{{ $report->resolution_note ? __('Update Resolution Note') : __('Add Resolution Note') }}</button>
                                </form>
                                <form method="POST" action="{{ route('gallery.reports.reopen', [$recipe, $report]) }}" class="mt-3">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="button secondary">{{ __('Reopen Report') }}</button>
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
                    'mt-3 rounded border p-3 text-sm',
                    'border-red-200 bg-red-50 text-red-700' => $currentReport->resolved_at === null,
                    'border-green-200 bg-green-50 text-green-700' => $currentReport->resolved_at !== null,
                ])>
                    {{ $currentReport->resolved_at === null
                        ? __('You reported this recipe as :reason. You can update or withdraw your report.', ['reason' => str($currentReport->reason)->headline()])
                        : __('The contributor marked your :reason report as resolved. Updating it will reopen it.', ['reason' => str($currentReport->reason)->headline()]) }}
                </p>
                @if ($currentReport->resolved_at && $currentReport->resolution_note)
                    <div class="mt-3 rounded border border-green-200 bg-green-50 p-3 text-sm text-green-800">
                        <p class="font-semibold">{{ __('Contributor resolution note') }}</p>
                        <p class="mt-1 whitespace-pre-line">{{ $currentReport->resolution_note }}</p>
                    </div>
                @endif
            @endif
            <form method="POST" action="{{ route('gallery.report.store', $recipe) }}" class="mt-4 space-y-4">
                @csrf
                <div>
                    <label for="reason" class="block text-xs font-semibold uppercase text-secondary">{{ __('Issue type') }}</label>
                    <select id="reason" name="reason" class="input secondary mt-1 w-full rounded sm:max-w-xs" required>
                        <option value="">{{ __('Choose an issue') }}</option>
                        @foreach (\App\Models\RecipeReport::REASONS as $reason)
                            <option value="{{ $reason }}" @selected(old('reason', $currentReport?->reason) === $reason)>{{ str($reason)->headline() }}</option>
                        @endforeach
                    </select>
                    <x-forms.errors name="reason" />
                </div>
                <div>
                    <label for="details" class="block text-xs font-semibold uppercase text-secondary">{{ __('Details (optional)') }}</label>
                    <textarea id="details" name="details" rows="4" maxlength="1000" class="input secondary mt-1 w-full rounded" placeholder="{{ __('Explain what the contributor should review.') }}">{{ old('details', $currentReport?->details) }}</textarea>
                    <x-forms.errors name="details" />
                </div>
                <button type="submit" class="button primary">{{ $currentReport ? __('Update Report') : __('Submit Report') }}</button>
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
                    <button type="submit" class="button secondary">{{ __('Withdraw Report') }}</button>
                </form>
            @endif
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
