<x-layouts.app>
    @php
        $reportDialogHasErrors = old('_gallery_report_form') === '1' && $errors->hasAny(['reason', 'details']);
        $reportDialogOpen = (request()->query('dialog') === 'report' && ! session()->has('status')) || $reportDialogHasErrors;
        $reportDialogUrl = route('gallery.show', ['recipe' => $recipe, 'dialog' => 'report']);
        $galleryPageUrl = request()->fullUrlWithoutQuery('dialog');
        $recipeEditDialogId = 'gallery-recipe-edit-dialog';
        $recipeEditOpen = request()->query('dialog') === 'edit-recipe' && $installedRecipe !== null;
        $recipeEditUrl = (string) \Illuminate\Support\Uri::of($galleryPageUrl)->withQuery(['dialog' => 'edit-recipe']);
    @endphp

    <x-layouts.partials.breadcrumbs :route="route('gallery.index')" :title="__('Back to gallery')" />

    <x-layouts.partials.heading
        :title="$recipe->name"
        :description="$recipe->description"
    >
        <x-slot:buttons>
            @if ((int) $recipe->user_id !== (int) auth()->id())
                <x-ui.button
                    href="{{ $reportDialogUrl }}"
                    data-modal-trigger="gallery-report-dialog"
                    aria-controls="gallery-report-dialog"
                    aria-expanded="{{ $reportDialogOpen ? 'true' : 'false' }}"
                    variant="secondary"
                >
                    {{ __('Report issue') }}
                </x-ui.button>
            @endif
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
                <x-ui.button href="{{ $recipeEditUrl }}" data-modal-trigger="{{ $recipeEditDialogId }}" aria-controls="{{ $recipeEditDialogId }}" aria-expanded="{{ $recipeEditOpen ? 'true' : 'false' }}" variant="secondary">{{ __('View My Copy') }}</x-ui.button>
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

    <x-ui.local-nav class="mt-6" :label="__('Recipe sections')">
        <a href="#recipe-details-insights" class="ui-local-nav__link">{{ __('Overview') }}</a>
        <a href="#gallery-rating" class="ui-local-nav__link">{{ __('Rating') }}</a>
        <a href="#gallery-feedback" class="ui-local-nav__link">{{ __('Feedback') }}</a>
        <a href="#gallery-script" class="ui-local-nav__link">{{ __('Script') }}</a>
    </x-ui.local-nav>

    @if (session('status'))
        <x-ui.alert class="my-4" tone="success" role="status">{{ session('status') }}</x-ui.alert>
    @endif

    <x-ui.insights id="recipe-details-insights" class="mt-6 scroll-mt-24" :summary="__('Recipe details')">
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

    <section id="gallery-rating" class="ui-panel mt-6 scroll-mt-24 p-5 sm:p-6" aria-labelledby="gallery-rating-heading">
        <h2 id="gallery-rating-heading" class="text-lg font-bold text-ink">{{ __('Rate this recipe') }}</h2>
        @if ($canRate)
            <p class="mt-1 text-sm text-muted">{{ __('Ratings are limited to people who installed the recipe. You can change or remove yours at any time.') }}</p>
            <div class="mt-4 flex flex-wrap items-end gap-3">
                <form method="POST" action="{{ route('gallery.rating.store', $recipe) }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <div>
                        <label for="rating" class="ui-label">{{ __('Your rating') }}</label>
                        <select id="rating" name="rating" class="ui-input" required>
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
            <p class="mt-1 text-sm text-muted">{{ __('Contributors cannot rate their own recipes.') }}</p>
        @else
            <p class="mt-1 text-sm text-muted">{{ __('Add this recipe to your account before rating it.') }}</p>
        @endif
    </section>

    <section id="gallery-feedback" class="ui-panel mt-6 scroll-mt-24 p-5 sm:p-6" aria-labelledby="gallery-report-heading">
        @if ((int) $recipe->user_id === (int) auth()->id())
            @php
                $reportTotal = $reportCounts->sum();
            @endphp
            <h2 id="gallery-report-heading" class="text-lg font-bold text-ink">{{ __('Community reports') }}</h2>
            <p class="mt-1 text-sm text-muted">
                {{ __('Reporter identities are private. Use this anonymous feedback to investigate and improve your published recipe.') }}
            </p>
            <a href="{{ route('gallery.reports.index') }}" class="ui-link mt-2 inline-block text-sm">{{ __('Open all community feedback') }}</a>

            @if ($recentReports->isNotEmpty())
                @if ($reportTotal > 0)
                    <div class="mt-4 flex flex-wrap gap-2">
                        @foreach (\App\Modules\Deployer\Models\RecipeReport::REASONS as $reason)
                            @if ($reportCounts->has($reason))
                                <x-ui.badge tone="danger">
                                    {{ str($reason)->headline() }}: {{ $reportCounts->get($reason) }}
                                </x-ui.badge>
                            @endif
                        @endforeach
                    </div>
                @else
                    <p class="mt-4 text-sm text-success">{{ __('All recent community reports have been resolved.') }}</p>
                @endif
                <div class="mt-4 space-y-3">
                    @foreach ($recentReports as $report)
                        <article class="ui-card ui-card--muted p-4">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm font-semibold text-ink">{{ str($report->reason)->headline() }}</span>
                                    <x-ui.badge :tone="$report->resolved_at === null ? 'danger' : 'success'">{{ $report->resolved_at === null ? __('Needs review') : __('Resolved') }}</x-ui.badge>
                                </div>
                                <span class="text-xs text-muted">{{ $report->created_at->diffForHumans() }}</span>
                            </div>
                            <p class="mt-2 whitespace-pre-line text-sm text-muted">{{ $report->details ?: __('No additional details were provided.') }}</p>
                            @if ($report->resolved_at && $report->resolution_note)
                                <div class="ui-alert ui-alert--success mt-3 p-3">
                                    <p class="text-xs font-semibold uppercase">{{ __('Resolution note') }}</p>
                                    <p class="mt-1 whitespace-pre-line text-sm">{{ $report->resolution_note }}</p>
                                </div>
                            @endif
                            @php
                                $resolutionDialogId = 'gallery-report-resolution-'.$report->id;
                                $resolutionDialogKey = 'resolve-report-'.$report->id;
                                $resolutionDialogOpen = request()->query('dialog') === $resolutionDialogKey
                                    || ((string) old('_gallery_resolution_report_id') === (string) $report->id && $errors->has('resolution_note'));
                                $resolutionDialogUrl = route('gallery.show', ['recipe' => $recipe, 'dialog' => $resolutionDialogKey]);
                            @endphp
                            @if ($report->resolved_at === null)
                                <x-scenes.gallery.report-resolution-dialog
                                    :dialog-id="$resolutionDialogId"
                                    :dialog-open="$resolutionDialogOpen"
                                    :dialog-url="$resolutionDialogUrl"
                                    :form-action="route('gallery.reports.resolve', [$recipe, $report])"
                                    :report="$report"
                                    :resolved="false"
                                />
                            @else
                                <x-scenes.gallery.report-resolution-dialog
                                    :dialog-id="$resolutionDialogId"
                                    :dialog-open="$resolutionDialogOpen"
                                    :dialog-url="$resolutionDialogUrl"
                                    :form-action="route('gallery.reports.resolution-note.update', [$recipe, $report])"
                                    :report="$report"
                                    :resolved="true"
                                />
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
                <p class="mt-4 text-sm text-muted">{{ __('No community reports have been submitted for this recipe.') }}</p>
            @endif
        @else
            <h2 id="gallery-report-heading" class="text-lg font-bold text-ink">{{ __('Report a recipe issue') }}</h2>
            <p class="mt-1 text-sm text-muted">
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
            <x-ui.button
                href="{{ $reportDialogUrl }}"
                data-modal-trigger="gallery-report-dialog"
                aria-controls="gallery-report-dialog"
                aria-expanded="{{ $reportDialogOpen ? 'true' : 'false' }}"
                variant="primary"
                class="mt-4"
            >
                {{ $currentReport ? __('Update Report') : __('Open report form') }}
            </x-ui.button>
            <x-scenes.gallery.report-dialog
                :current-report="$currentReport"
                :open="$reportDialogOpen"
                :recipe="$recipe"
            />
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
    </section>

    <section id="gallery-script" class="ui-panel mt-6 scroll-mt-24 p-5 sm:p-6" aria-labelledby="gallery-script-heading">
        <x-ui.alert tone="warning" class="p-3">
            {{ __('This community script runs as root. Read every command and verify package sources, downloads, and destructive operations before using it.') }}
        </x-ui.alert>
        <h2 id="gallery-script-heading" class="mt-5 text-lg font-bold text-ink">{{ __('Bash script') }}</h2>
        <pre class="ui-console mt-3 overflow-x-auto p-4 text-sm leading-6"><code>{{ $recipe->script }}</code></pre>
    </section>

    <p class="mt-4 text-xs text-muted">
        {{ __('Published :date. Adding this recipe creates a private snapshot you can review and edit independently.', ['date' => $recipe->published_at->diffForHumans()]) }}
    </p>

    @if ($installedRecipe)
        <x-scenes.recipes.edit-dialog
            :id="$recipeEditDialogId"
            :recipe="$installedRecipe"
            :open="$recipeEditOpen"
            :cancel-url="$galleryPageUrl"
            field-prefix="gallery-recipe-edit-"
        />
    @endif
</x-layouts.app>
