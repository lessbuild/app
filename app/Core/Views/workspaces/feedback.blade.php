<x-signal.layouts.platform
    :title="__('Product feedback')"
    :description="__('Send feedback for Deployer, Monitor, or Analytics and review workspace submissions.')"
    :navigation="[]"
    :account-user="$user"
    :current-workspace="$workspace"
    :workspaces="$workspaces"
>
    <x-signal.ui.page-header
        eyebrow="{{ $workspace->name }}"
        :title="__('Product feedback')"
        :description="__('Report a bug, share an idea, or tell the team where an app became confusing.')"
    >
        <x-slot:actions>
            <x-signal.ui.button :href="route('core.workspace.admin', $workspace)" variant="secondary">{{ __('Workspace management') }}</x-signal.ui.button>
            <x-signal.ui.button :href="route('core.help')" variant="quiet">{{ __('Help and guides') }}</x-signal.ui.button>
        </x-slot:actions>
    </x-signal.ui.page-header>

    @if (count($products))
        <x-signal.ui.card class="mt-7 p-5 sm:p-6">
            <h2 class="text-lg font-extrabold text-ink">{{ __('Send feedback') }}</h2>
            <p class="mt-1 text-sm text-muted">{{ __('Your description and reproduction steps are stored encrypted and visible only to authorized workspace members.') }}</p>

            <form method="POST" action="{{ route('core.workspace.feedback.store', $workspace) }}" class="mt-5 grid gap-4 lg:grid-cols-2">
                @csrf
                <div>
                    <label for="feedback-product" class="ui-label">{{ __('App') }}</label>
                    <x-signal.ui.select id="feedback-product" name="product" required aria-describedby="feedback-product-error">
                        @foreach ($products as $availableProduct)
                            <option value="{{ $availableProduct }}" @selected(old('product') === $availableProduct)>{{ str($availableProduct)->headline() }}</option>
                        @endforeach
                    </x-signal.ui.select>
                    @error('product')<p id="feedback-product-error" class="mt-1 text-xs font-semibold text-danger">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="feedback-category" class="ui-label">{{ __('Type') }}</label>
                    <x-signal.ui.select id="feedback-category" name="category" required aria-describedby="feedback-category-error">
                        @foreach (\App\Core\Models\WorkspaceFeedback::CATEGORIES as $value)
                            <option value="{{ $value }}" @selected(old('category', 'bug') === $value)>{{ str($value)->headline() }}</option>
                        @endforeach
                    </x-signal.ui.select>
                    @error('category')<p id="feedback-category-error" class="mt-1 text-xs font-semibold text-danger">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="feedback-severity" class="ui-label">{{ __('Priority') }}</label>
                    <x-signal.ui.select id="feedback-severity" name="severity" required aria-describedby="feedback-severity-error">
                        @foreach (\App\Core\Models\WorkspaceFeedback::SEVERITIES as $value)
                            <option value="{{ $value }}" @selected(old('severity', 'normal') === $value)>{{ str($value)->headline() }}</option>
                        @endforeach
                    </x-signal.ui.select>
                    @error('severity')<p id="feedback-severity-error" class="mt-1 text-xs font-semibold text-danger">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="feedback-title" class="ui-label">{{ __('Title') }}</label>
                    <x-signal.ui.input id="feedback-title" name="title" required maxlength="160" :value="old('title')" aria-describedby="feedback-title-error" />
                    @error('title')<p id="feedback-title-error" class="mt-1 text-xs font-semibold text-danger">{{ $message }}</p>@enderror
                </div>

                <div class="lg:col-span-2">
                    <label for="feedback-description" class="ui-label">{{ __('What happened or what would you change?') }}</label>
                    <x-signal.ui.textarea id="feedback-description" name="description" required maxlength="10000" rows="5" aria-describedby="feedback-description-error">{{ old('description') }}</x-signal.ui.textarea>
                    @error('description')<p id="feedback-description-error" class="mt-1 text-xs font-semibold text-danger">{{ $message }}</p>@enderror
                </div>

                <div class="lg:col-span-2">
                    <label for="feedback-reproduction" class="ui-label">{{ __('Reproduction steps (optional)') }}</label>
                    <x-signal.ui.textarea id="feedback-reproduction" name="reproduction_steps" maxlength="10000" rows="3" aria-describedby="feedback-reproduction-error">{{ old('reproduction_steps') }}</x-signal.ui.textarea>
                    @error('reproduction_steps')<p id="feedback-reproduction-error" class="mt-1 text-xs font-semibold text-danger">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="feedback-page" class="ui-label">{{ __('Page path (optional)') }}</label>
                    <x-signal.ui.input id="feedback-page" name="page" maxlength="500" placeholder="/projects" :value="old('page')" aria-describedby="feedback-page-error" />
                    @error('page')<p id="feedback-page-error" class="mt-1 text-xs font-semibold text-danger">{{ $message }}</p>@enderror
                </div>

                <div class="flex items-end justify-end">
                    <x-signal.ui.button type="submit" variant="primary">{{ __('Send feedback') }}</x-signal.ui.button>
                </div>
            </form>
        </x-signal.ui.card>
    @else
        <x-signal.ui.alert class="mt-7" tone="info">{{ __('You need access to at least one app in this workspace before sending product feedback.') }}</x-signal.ui.alert>
    @endif

    <section class="mt-8" aria-labelledby="feedback-list-title">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="ui-eyebrow">{{ $canReview ? __('Workspace submissions') : ($reviewableProducts ? __('Feedback you can review') : __('Your submissions')) }}</p>
                <h2 id="feedback-list-title" class="mt-1 text-xl font-extrabold text-ink">{{ __('Feedback history') }}</h2>
            </div>
            <form method="GET" class="flex flex-wrap items-end gap-2">
                <div>
                    <label for="feedback-filter-product" class="sr-only">{{ __('App') }}</label>
                    <x-signal.ui.select id="feedback-filter-product" name="product">
                        <option value="">{{ __('Every app') }}</option>
                        @foreach (['deployer', 'monitor', 'analytics'] as $value)
                            <option value="{{ $value }}" @selected($product === $value)>{{ str($value)->headline() }}</option>
                        @endforeach
                    </x-signal.ui.select>
                </div>
                <div>
                    <label for="feedback-filter-status" class="sr-only">{{ __('Status') }}</label>
                    <x-signal.ui.select id="feedback-filter-status" name="status">
                        <option value="">{{ __('Every status') }}</option>
                        @foreach (\App\Core\Models\WorkspaceFeedback::STATUSES as $value)
                            <option value="{{ $value }}" @selected($status === $value)>{{ str($value)->headline() }}</option>
                        @endforeach
                    </x-signal.ui.select>
                </div>
                <x-signal.ui.button type="submit" variant="secondary">{{ __('Filter') }}</x-signal.ui.button>
                @if ($product || $status || $category)
                    <x-signal.ui.button :href="route('core.workspace.feedback.index', $workspace)" variant="quiet">{{ __('Clear') }}</x-signal.ui.button>
                @endif
            </form>
        </div>

        <div class="grid gap-3">
            @forelse ($feedback as $item)
                @php($reviewId = 'feedback-review-'.$item->getKey())
                @php($canReviewItem = $canReview || in_array($item->product, $reviewableProducts, true))
                <x-signal.ui.card as="article" class="p-5 sm:p-6">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap gap-2">
                                <x-signal.ui.badge>{{ str($item->product)->headline() }}</x-signal.ui.badge>
                                <x-signal.ui.badge>{{ str($item->category)->headline() }}</x-signal.ui.badge>
                                <x-signal.ui.badge :tone="in_array($item->severity, ['high', 'blocking'], true) ? 'warning' : 'neutral'">{{ str($item->severity)->headline() }}</x-signal.ui.badge>
                                <x-signal.ui.badge :tone="in_array($item->status, ['resolved', 'closed'], true) ? 'success' : ($item->status === 'reviewing' ? 'accent' : 'neutral')">{{ str($item->status)->headline() }}</x-signal.ui.badge>
                            </div>
                            <h3 class="mt-3 text-lg font-extrabold text-ink">{{ $item->title }}</h3>
                            <p class="mt-1 text-xs text-muted">{{ __('Submitted by :name :time', ['name' => $item->submitter?->name ?? __('Former member'), 'time' => $item->created_at->diffForHumans()]) }} @if ($item->page) · <code>{{ $item->page }}</code> @endif</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if ($canReviewItem)
                                <x-signal.ui.button :href="'#'.$reviewId" variant="secondary">{{ __('Review') }}</x-signal.ui.button>
                            @endif
                            @if ($canReviewItem || (string) $item->user_id === (string) $user->getKey())
                                <form method="POST" action="{{ route('core.workspace.feedback.destroy', [$workspace, $item]) }}" onsubmit="return confirm(@js(__('Remove this feedback permanently?')))" >
                                    @csrf
                                    @method('DELETE')
                                    <x-signal.ui.button type="submit" variant="danger">{{ __('Delete') }}</x-signal.ui.button>
                                </form>
                            @endif
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
                        <div class="mt-4 rounded-panel border border-line bg-surface-muted/60 p-4">
                            <p class="text-xs font-extrabold uppercase tracking-wide text-subtle">{{ __('Buildpusher response') }}</p>
                            <p class="mt-2 whitespace-pre-wrap text-sm leading-6 text-ink">{{ $item->review_response }}</p>
                        </div>
                    @endif

                    @if ($canReviewItem)
                        <details id="{{ $reviewId }}" class="mt-4 border-t border-line pt-4">
                            <summary class="ui-link cursor-pointer text-sm font-bold">{{ __('Update review') }}</summary>
                            <form method="POST" action="{{ route('core.workspace.feedback.update', [$workspace, $item]) }}" class="mt-4 grid gap-3 sm:grid-cols-2">
                                @csrf
                                @method('PATCH')
                                <div>
                                    <label for="{{ $reviewId }}-status" class="ui-label">{{ __('Status') }}</label>
                                    <x-signal.ui.select id="{{ $reviewId }}-status" name="status" required>
                                        @foreach (\App\Core\Models\WorkspaceFeedback::STATUSES as $value)
                                            <option value="{{ $value }}" @selected($item->status === $value)>{{ str($value)->headline() }}</option>
                                        @endforeach
                                    </x-signal.ui.select>
                                </div>
                                <div class="sm:col-span-2">
                                    <label for="{{ $reviewId }}-response" class="ui-label">{{ __('Response') }}</label>
                                    <x-signal.ui.textarea id="{{ $reviewId }}-response" name="review_response" maxlength="10000">{{ $item->review_response }}</x-signal.ui.textarea>
                                </div>
                                <div class="sm:col-span-2 sm:text-right">
                                    <x-signal.ui.button type="submit" variant="primary">{{ __('Save review') }}</x-signal.ui.button>
                                </div>
                            </form>
                        </details>
                    @endif
                </x-signal.ui.card>
            @empty
                <x-signal.ui.empty-state :title="__('No feedback yet')" :description="__('Submissions for this workspace will appear here.')" icon="information-circle" />
            @endforelse
        </div>

        <div class="mt-5">{{ $feedback->links() }}</div>
    </section>

    @if (! $legacyFeedback->available)
        <x-signal.ui.alert class="mt-8" tone="warning">
            {{ __('Older Deployer feedback could not be loaded. Its records remain unchanged in the Deployer database; try again later or open Deployer from workspace management.') }}
        </x-signal.ui.alert>
    @elseif ($legacyFeedback->entries !== [])
        <section class="mt-8" aria-labelledby="legacy-deployer-feedback-heading">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="ui-eyebrow">{{ __('Existing Deployer records') }}</p>
                    <h2 id="legacy-deployer-feedback-heading" class="mt-1 text-xl font-extrabold text-ink">{{ __('Older Deployer feedback') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-6 text-muted">{{ __('These records stay in Deployer and are shown here as read-only history. Use the Deployer feedback tools in workspace management to review or change them.') }}</p>
                </div>
                @if ($legacyFeedback->manageUrl)
                    <x-signal.ui.button :href="$legacyFeedback->manageUrl" variant="secondary">
                        {{ __('Open in Deployer') }}
                        <x-signal.ui.icon name="arrow-up-right" class="size-4" />
                    </x-signal.ui.button>
                @endif
            </div>

            <div class="grid gap-3">
                @foreach ($legacyFeedback->entries as $item)
                    <x-signal.ui.card as="article" class="p-5 sm:p-6">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap gap-2">
                                    <x-signal.ui.badge>{{ __('Deployer') }}</x-signal.ui.badge>
                                    <x-signal.ui.badge>{{ str($item->category)->headline() }}</x-signal.ui.badge>
                                    <x-signal.ui.badge :tone="in_array($item->severity, ['high', 'blocking'], true) ? 'warning' : 'neutral'">{{ str($item->severity)->headline() }}</x-signal.ui.badge>
                                    <x-signal.ui.badge :tone="in_array($item->status, ['resolved', 'closed'], true) ? 'success' : ($item->status === 'reviewing' ? 'accent' : 'neutral')">{{ str($item->status)->headline() }}</x-signal.ui.badge>
                                </div>
                                <h3 class="mt-3 text-lg font-extrabold text-ink">{{ $item->title }}</h3>
                                <p class="mt-1 text-xs text-muted">{{ __('Submitted by :name :time', ['name' => $item->submitterName ?? __('Former member'), 'time' => $item->createdAt->diffForHumans()]) }} @if ($item->page) · <code>{{ $item->page }}</code> @endif</p>
                            </div>
                            <x-signal.ui.badge tone="neutral">{{ __('Read only') }}</x-signal.ui.badge>
                        </div>

                        <p class="mt-4 whitespace-pre-wrap text-sm leading-6 text-muted">{{ $item->description }}</p>
                        @if ($item->reproductionSteps)
                            <details class="mt-3">
                                <summary class="ui-link cursor-pointer text-sm">{{ __('Reproduction steps') }}</summary>
                                <p class="mt-2 whitespace-pre-wrap rounded-card bg-surface-muted p-3 text-sm text-ink">{{ $item->reproductionSteps }}</p>
                            </details>
                        @endif
                        @if ($item->reviewResponse)
                            <div class="mt-4 rounded-panel border border-line bg-surface-muted/60 p-4">
                                <p class="text-xs font-extrabold uppercase tracking-wide text-subtle">{{ __('Buildpusher response') }}</p>
                                <p class="mt-2 whitespace-pre-wrap text-sm leading-6 text-ink">{{ $item->reviewResponse }}</p>
                            </div>
                        @endif
                    </x-signal.ui.card>
                @endforeach
            </div>
        </section>
    @endif
</x-signal.layouts.platform>
