<x-layouts.app>

    @php
        $repositoryIndexQuery = array_filter($filters, fn ($value) => $value !== null);
        $repositoryCreateOpen = request()->query('dialog') === 'create-repository';
        $repositoryCreateUrl = route('repositories.index', [...$repositoryIndexQuery, 'dialog' => 'create-repository']);
        $impactPreviewDialogOpen = request()->query('dialog') === 'impact-preview';
        $impactPreviewDialogUrl = route('repositories.index', [...$repositoryIndexQuery, 'dialog' => 'impact-preview']);
        $impactPreviewContentUrl = route('repositories.impact-preview', ['fragment' => 'repository-impact-preview']);
    @endphp

    <!--
     ! ------------------------------------------------------------
     ! Heading
     ! ------------------------------------------------------------
     !-->
    <x-layouts.partials.heading
        eyebrow="{{ __('Source control') }}"
        icon="code"
        :title="__('Repositories')"
        :description="__('Manage source targets and review their latest filtered deployment state.')"
    >
        <x-slot:buttons>
            <x-ui.button
                :href="$impactPreviewDialogUrl"
                data-modal-trigger="repository-impact-preview-dialog"
                data-modal-content-url="{{ $impactPreviewContentUrl }}"
                data-modal-history-url="{{ $impactPreviewDialogUrl }}"
                aria-controls="repository-impact-preview-dialog"
                aria-expanded="{{ $impactPreviewDialogOpen ? 'true' : 'false' }}"
                variant="secondary"
            >
                {{ __('Preview push impact') }}
            </x-ui.button>
            <x-ui.button
                :href="$repositoryCreateUrl"
                data-modal-trigger="repository-create-dialog"
                aria-controls="repository-create-dialog"
                aria-expanded="{{ $repositoryCreateOpen ? 'true' : 'false' }}"
                variant="primary"
            >
                <svg class="h-4 w-4" aria-hidden="true">
                    <use xlink:href="/assets/images/icons.svg#plus-circle"></use>
                </svg>
                {{ __('Add Repository') }}
            </x-ui.button>
        </x-slot:buttons>
    </x-layouts.partials.heading>

    @php($activeFilterCount = count(array_filter($filters, fn ($value) => $value !== null && $value !== '')))

    <x-ui.filter-panel
        id="repositories-filters"
        class="mt-8"
        :label="__('Filter repositories')"
        :open="$activeFilterCount > 0"
        :summary="$activeFilterCount > 0 ? __(':count active', ['count' => $activeFilterCount]) : null"
    >
        <form method="GET" action="{{ route('repositories.index') }}">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div>
                    <label for="search" class="ui-label">{{ __('Search') }}</label>
                    <input
                        id="search"
                        name="search"
                        type="search"
                        maxlength="100"
                        value="{{ $filters['search'] }}"
                        placeholder="{{ __('Name, URL, or description') }}"
                        class="ui-input mt-1 w-full"
                    >
                </div>
                <div>
                    <label for="provider_id" class="ui-label">{{ __('Provider') }}</label>
                    <select id="provider_id" name="provider_id" class="ui-input mt-1 w-full">
                        <option value="">{{ __('All providers') }}</option>
                        @foreach ($providers as $provider)
                            <option value="{{ $provider->id }}" @selected((int) $filters['provider_id'] === $provider->id)>
                                {{ $provider->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="website_id" class="ui-label">{{ __('Website') }}</label>
                    <select id="website_id" name="website_id" class="ui-input mt-1 w-full">
                        <option value="">{{ __('All websites') }}</option>
                        @foreach ($websites as $website)
                            <option value="{{ $website->id }}" @selected((int) $filters['website_id'] === $website->id)>
                                {{ $website->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="status" class="ui-label">{{ __('Latest deployment') }}</label>
                    <select id="status" name="status" class="ui-input mt-1 w-full">
                        <option value="">{{ __('All deployment states') }}</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected($filters['status'] === $status)>
                                {{ $status === 'none' ? __('Never deployed') : str($status)->replace('_', ' ')->title() }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="mt-4 flex flex-wrap gap-3">
                <x-ui.button type="submit" variant="primary">{{ __('Apply filters') }}</x-ui.button>
                <x-ui.button :href="route('repositories.export', array_filter($filters, fn ($value) => $value !== null))" variant="secondary">
                    {{ __('Export CSV') }}
                </x-ui.button>
                @if (array_filter($filters, fn ($value) => $value !== null))
                    <x-ui.button :href="route('repositories.index')" variant="ghost">{{ __('Clear filters') }}</x-ui.button>
                @endif
            </div>
        </form>
    </x-ui.filter-panel>

    <x-ui.insights
        id="repositories-insights"
        class="mt-6"
        :summary="trans_choice(':count matching repository|:count matching repositories', $metrics['total'], ['count' => $metrics['total']])"
    >
        <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
            <x-ui.stat :label="__('Matching repositories')" :value="$metrics['total']" :description="__('Repositories in this filtered view.')" />
            <x-ui.stat :label="__('Never deployed')" :value="$metrics['never_deployed']" :description="__('Matching repositories without a build.')" />
            <x-ui.stat :label="__('Active deployments')" :value="$metrics['active']" :description="__('Latest deployment is still active.')" />
            <x-ui.stat :label="__('Latest succeeded')" :value="$metrics['succeeded']" :description="__('Latest deployment completed successfully.')" />
            <x-ui.stat :label="__('Latest failed')" :value="$metrics['failed']" :description="__('Latest deployment failed.')" />
            <x-ui.stat :label="__('Push webhooks')" :value="$metrics['webhooks']" :description="__('Matching repositories with webhooks enabled.')" />
        </dl>
    </x-ui.insights>

    <!--
     ! ------------------------------------------------------------
    ! List Repositories
     ! ------------------------------------------------------------
     !-->
    @if(!$repositories->isEmpty())
        <div class="ui-card ui-inventory-list mt-6 divide-y divide-line overflow-hidden" aria-label="{{ __('Repository inventory') }}">
            @foreach($repositories as $repository)
                <article data-repository-card class="p-4 sm:p-5">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="flex min-w-0 items-center gap-3">
                            <x-avatar :name="$repository->name" class="h-10 w-10 shrink-0 rounded-md text-sm" />
                            <div class="min-w-0">
                                <a href="{{ route('repositories.show', $repository) }}" class="ui-link font-semibold">{{ $repository->name }}</a>
                                <p class="truncate text-sm text-muted">{{ $repository->url }}</p>
                            </div>
                        </div>
                        <x-ui.button :href="route('repositories.show', $repository)" variant="secondary">{{ __('View repository') }}</x-ui.button>
                    </div>

                    @if ($repository->description)
                        <p class="mt-3 text-sm text-muted">{{ $repository->description }}</p>
                    @endif

                    <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                        <div>
                            <dt class="text-xs font-bold uppercase tracking-wide text-muted">{{ __('Deployment target') }}</dt>
                            <dd class="mt-1 text-ink">
                                @if ($repository->website && ! $repository->website->trashed())
                                    <a href="{{ route('websites.show', $repository->website) }}" class="ui-link font-medium">{{ $repository->website->name }}</a>
                                    <span class="mt-1 block text-muted">{{ $repository->website->server?->label ?? __('Server unavailable') }}</span>
                                @elseif ($repository->website)
                                    <span class="font-medium text-muted">{{ __('Deleted website') }}</span>
                                    <span class="mt-1 block text-muted">{{ $repository->website->name }}</span>
                                @else
                                    {{ __('Website unavailable') }}
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-bold uppercase tracking-wide text-muted">{{ __('Provider') }}</dt>
                            <dd class="mt-1 text-ink">{{ $repository->provider?->name ?? __('Provider unavailable') }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-bold uppercase tracking-wide text-muted">{{ __('Latest deployment') }}</dt>
                            <dd class="mt-1 text-ink">
                                @if ($repository->latestBuild)
                                    <a href="{{ route('builds.show', $repository->latestBuild) }}" @class([
                                        'font-semibold uppercase hover:underline',
                                        'text-success' => $repository->latestBuild->status === \App\Models\Build::STATUS_SUCCEEDED,
                                        'text-danger' => $repository->latestBuild->status === \App\Models\Build::STATUS_FAILED,
                                        'text-muted' => ! in_array($repository->latestBuild->status, [\App\Models\Build::STATUS_SUCCEEDED, \App\Models\Build::STATUS_FAILED], true),
                                    ])>{{ str($repository->latestBuild->status)->replace('_', ' ') }}</a>
                                    <span class="mt-1 block text-muted">{{ $repository->latestBuild->created_at->diffForHumans() }}</span>
                                @else
                                    {{ __('Never deployed') }}
                                @endif
                            </dd>
                        </div>
                    </dl>
                </article>
            @endforeach
        </div>
        <div class="py-4">
            {{ $repositories->links() }}
        </div>
    @else
        <div class="max-w-3xl mx-auto">
            <x-ui.empty-state
                :title="array_filter($filters, fn ($value) => $value !== null) ? __('No repositories match these filters') : __('You have no repositories')"
                :description="array_filter($filters, fn ($value) => $value !== null) ? __('Try changing or clearing the selected filters.') : __('You have no repositories. Click the button below to add one.')"
            >
                <x-slot:action>
                    @if (array_filter($filters, fn ($value) => $value !== null))
                        <x-ui.button :href="route('repositories.index')" variant="secondary">{{ __('Clear filters') }}</x-ui.button>
                    @else
                        <x-ui.button
                            :href="$repositoryCreateUrl"
                            data-modal-trigger="repository-create-dialog"
                            aria-controls="repository-create-dialog"
                            aria-expanded="{{ $repositoryCreateOpen ? 'true' : 'false' }}"
                            variant="primary"
                        >
                            {{ __('Add Repository') }}
                        </x-ui.button>
                    @endif
                </x-slot:action>
            </x-ui.empty-state>
        </div>
    @endif

    <x-dialogs.modal
        id="repository-impact-preview-dialog"
        :title="__('Deployment impact preview')"
        :description="__('See which enabled repository targets are affected by a changed-file set before any automatic push deployment.')"
        :open="$impactPreviewDialogOpen"
    >
        <div data-modal-content>
            <div class="space-y-3 text-sm text-muted">{{ __('Loading deployment impact preview…') }}</div>
        </div>
    </x-dialogs.modal>

    <x-scenes.repositories.create-dialog
        :providers="$providers"
        :websites="$websites"
        :index-query="$repositoryIndexQuery"
        :open="$repositoryCreateOpen"
    />
</x-layouts.app>
