<x-layouts.app>
    @php
        $feedbackDialogOpen = request()->query('dialog') === 'compose-feedback'
            || ($errors->hasAny(['category', 'severity', 'title', 'description', 'reproduction_steps', 'page']) && request()->query('dialog') === null);
        $feedbackDialogUrl = route('feedback.index', array_filter([
            'dialog' => 'compose-feedback',
            'status' => $status,
            'category' => $category,
        ], static fn ($value): bool => filled($value)));
    @endphp

    <x-layouts.partials.heading
        eyebrow="{{ __('Product loop') }}"
        icon="information-circle"
        :title="__('Product feedback')"
        :description="__('Report a bug, share an idea, or tell us where the product became confusing.')"
    >
        <x-slot:buttons>
            <x-ui.button
                href="{{ $feedbackDialogUrl }}"
                data-modal-trigger="feedback-compose"
                aria-controls="feedback-compose"
                aria-expanded="{{ $feedbackDialogOpen ? 'true' : 'false' }}"
                variant="primary"
            >
                {{ __('Send feedback') }}
            </x-ui.button>
        </x-slot:buttons>
    </x-layouts.partials.heading>

    <x-ui.insights
        id="feedback-insights"
        class="mt-6"
        :summary="trans_choice(':count matching submission|:count matching submissions', $feedback->total(), ['count' => $feedback->total()])"
    >
        <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.stat
                :label="__('Matching')"
                :value="$feedback->total()"
                :description="__('Submissions in the current filtered view.')"
            />
            <x-ui.stat
                :label="__('This page')"
                :value="$feedback->count()"
                :description="__('Submissions loaded in this page of results.')"
            />
            <x-ui.stat
                :label="__('Status filter')"
                :value="$status ? str($status)->headline() : __('All statuses')"
                :description="__('Use the filter to focus review work.')"
            />
            <x-ui.stat
                :label="__('Access scope')"
                :value="$canReview ? __('Workspace') : __('Your submissions')"
                :description="__('Visibility is controlled by workspace permissions.')"
            />
        </dl>
    </x-ui.insights>

    <x-ui.local-nav :label="__('Feedback sections')">
        <a
            href="{{ $feedbackDialogUrl }}"
            class="ui-local-nav__link"
            data-modal-trigger="feedback-compose"
            aria-controls="feedback-compose"
            aria-expanded="{{ $feedbackDialogOpen ? 'true' : 'false' }}"
        >{{ __('Send feedback') }}</a>
        <a href="#feedback-list" class="ui-local-nav__link">{{ __('Workspace feedback') }}</a>
    </x-ui.local-nav>

    <div class="mt-8">
        <x-scenes.feedback.compose-dialog :open="$feedbackDialogOpen" />

        <section id="feedback-list" class="scroll-mt-24" aria-labelledby="feedback-list-heading">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h2 id="feedback-list-heading" class="text-lg font-extrabold text-ink">{{ __('Workspace feedback') }}</h2>
                <form method="GET" class="flex flex-wrap gap-2">
                    <label class="sr-only" for="feedback-status">{{ __('Status') }}</label>
                    <select id="feedback-status" name="status" class="ui-input"><option value="">{{ __('Every status') }}</option>@foreach (\App\Models\ProductFeedback::STATUSES as $value)<option value="{{ $value }}" @selected($status === $value)>{{ str($value)->headline() }}</option>@endforeach</select>
                    <label class="sr-only" for="feedback-filter-category">{{ __('Type') }}</label>
                    <select id="feedback-filter-category" name="category" class="ui-input"><option value="">{{ __('Every type') }}</option>@foreach (\App\Models\ProductFeedback::CATEGORIES as $value)<option value="{{ $value }}" @selected($category === $value)>{{ str($value)->headline() }}</option>@endforeach</select>
                    <x-ui.button type="submit" variant="secondary">{{ __('Filter') }}</x-ui.button>
                    @if ($status || $category)
                        <x-ui.button href="{{ route('feedback.index') }}" variant="ghost">{{ __('Clear') }}</x-ui.button>
                    @endif
                </form>
            </div>

            <div class="ui-inventory-list space-y-4">
                @forelse ($feedback as $item)
                    @php
                        $feedbackReviewDialogId = 'feedback-review-'.$item->id;
                        $feedbackReviewFormOld = old('_feedback_review_id') === (string) $item->id;
                        $feedbackReviewDialogOpen = request()->query('dialog') === $feedbackReviewDialogId
                            || ((string) old('_feedback_review_id') === (string) $item->id && $errors->any());
                        $feedbackReviewDialogUrl = route('feedback.index', array_filter([
                            'dialog' => $feedbackReviewDialogId,
                            'status' => $status,
                            'category' => $category,
                            'page' => $feedback->currentPage() > 1 ? $feedback->currentPage() : null,
                        ], static fn ($value): bool => filled($value)));
                    @endphp
                    <article class="ui-panel p-5 sm:p-6" data-feedback-card>
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap gap-2">
                                    <x-ui.badge tone="neutral">{{ str($item->category)->headline() }}</x-ui.badge>
                                    <x-ui.badge :tone="$item->severity === 'critical' ? 'danger' : ($item->severity === 'high' ? 'warning' : 'neutral')">{{ str($item->severity)->headline() }}</x-ui.badge>
                                    <x-ui.badge :tone="$item->status === 'resolved' ? 'success' : ($item->status === 'in_progress' ? 'accent' : 'neutral')">{{ str($item->status)->headline() }}</x-ui.badge>
                                </div>
                                <h3 class="mt-3 text-lg font-extrabold text-ink">{{ $item->title }}</h3>
                                <p class="mt-1 text-xs text-muted">{{ __('Submitted by :name :time', ['name' => $item->submitter->name, 'time' => $item->created_at->diffForHumans()]) }}@if ($item->page) <span aria-hidden="true">·</span> <code>{{ $item->page }}</code>@endif</p>
                            </div>
                            <div class="flex flex-wrap justify-end gap-2">
                                @if ($canReview)
                                    <x-ui.button
                                        :href="$feedbackReviewDialogUrl"
                                        data-modal-trigger="{{ $feedbackReviewDialogId }}"
                                        aria-controls="{{ $feedbackReviewDialogId }}"
                                        aria-expanded="{{ $feedbackReviewDialogOpen ? 'true' : 'false' }}"
                                        variant="secondary"
                                    >
                                        {{ __('Review') }}
                                    </x-ui.button>
                                @endif
                                <form method="POST" action="{{ route('feedback.destroy', $item) }}">
                                    @csrf
                                    @method('DELETE')
                                    <x-ui.button type="submit" variant="danger" onclick="return confirm({{ Illuminate\Support\Js::from(__('Remove this feedback permanently?')) }})">{{ __('Delete') }}</x-ui.button>
                                </form>
                            </div>
                        </div>

                        <p class="mt-4 whitespace-pre-wrap text-sm leading-6 text-muted">{{ $item->description }}</p>
                        @if ($item->reproduction_steps)
                            <details class="mt-3">
                                <summary class="ui-link cursor-pointer text-sm">{{ __('Reproduction steps') }}</summary>
                                <p class="mt-2 whitespace-pre-wrap rounded-card bg-surface-muted p-3 text-sm text-ink">{{ $item->reproduction_steps }}</p>
                            </details>
                        @endif
                        @if ($item->review_response)
                            <div class="ui-panel mt-4 bg-surface-muted p-4">
                                <p class="ui-eyebrow text-[0.65rem]">{{ __('Workspace response') }}</p>
                                <p class="mt-2 whitespace-pre-wrap text-sm text-ink">{{ $item->review_response }}</p>
                            </div>
                        @endif
                        @if ($canReview)
                            <x-scenes.feedback.review-dialog
                                :feedback="$item"
                                :form-old="$feedbackReviewFormOld"
                                :open="$feedbackReviewDialogOpen"
                            />
                        @endif
                    </article>
                @empty
                    <x-ui.empty-state
                        :title="__('No matching feedback')"
                        :description="__('Submitted feedback will appear here without exposing its content in notifications or exports.')"
                    />
                @endforelse
            </div>
            <div class="mt-5">{{ $feedback->links() }}</div>
        </section>
    </div>
</x-layouts.app>
