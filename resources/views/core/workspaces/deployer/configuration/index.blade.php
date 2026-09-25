<x-signal.layouts.platform
    :title="__('Applications and environments')"
    :description="__('Manage mapped Deployer preview and environment settings.')"
    :navigation="[]"
    :account-user="$user"
    :current-workspace="$workspace"
    :workspaces="$workspaces"
    :context-projects="[]"
>
    <x-signal.ui.page-header
        :eyebrow="$workspace->name.' · '.__('Deployer configuration')"
        :title="__('Applications and environments')"
        :description="__('Only active, exactly mapped applications that your Deployer role can manage appear here.')"
    >
        <x-slot:actions>
            <x-signal.ui.button :href="route('core.projects.index', $workspace)">{{ __('Shared projects') }}</x-signal.ui.button>
        </x-slot:actions>
    </x-signal.ui.page-header>

    <x-signal.ui.card class="mb-6 p-4 sm:p-5">
        <form method="GET" action="{{ route('core.workspace.deployer.configuration.index', $workspace) }}" class="flex flex-wrap items-end gap-3">
            <label class="min-w-56 flex-1">
                <span class="ui-label">{{ __('Search applications') }}</span>
                <input class="ui-input mt-1" type="search" name="q" value="{{ $projects->search }}" maxlength="100" placeholder="{{ __('Application name') }}">
            </label>
            <x-signal.ui.button type="submit">{{ __('Search') }}</x-signal.ui.button>
            @if ($projects->search)
                <x-signal.ui.button :href="route('core.workspace.deployer.configuration.index', $workspace)">{{ __('Clear') }}</x-signal.ui.button>
            @endif
        </form>
    </x-signal.ui.card>

    @if ($projects->projects === [])
        <x-signal.ui.empty-state
            :title="$projects->search ? __('No matching applications') : __('No mapped applications to configure')"
            :description="__('Applications without a current, unique Deployer mapping stay out of this directory.')"
            icon="view-grid"
        />
    @else
        <section aria-label="{{ __('Deployer applications') }}" class="grid gap-4 xl:grid-cols-2">
            @foreach ($projects->projects as $project)
                <x-signal.ui.card class="p-5 sm:p-6">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="ui-eyebrow">{{ __('Mapped application') }}</p>
                            <h2 class="mt-2 text-lg font-extrabold text-ink">{{ $project->projectName }}</h2>
                        </div>
                        <x-signal.ui.badge :tone="$project->previewEnabled ? 'success' : 'neutral'">
                            {{ $project->previewEnabled ? __('Previews enabled') : __('Previews disabled') }}
                        </x-signal.ui.badge>
                    </div>
                    <p class="mt-3 text-sm leading-6 text-muted">
                        {{ __('Preview policy is managed here. Environment values open on a scoped settings page.') }}
                    </p>
                    <div class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-line pt-4">
                        <span class="text-xs text-muted">{{ __('Preview lifetime: :hours hours', ['hours' => $project->previewTtlHours]) }}</span>
                        <a href="{{ route('core.workspace.deployer.configuration.projects.show', [$workspace, $project->projectId]) }}" class="inline-flex min-h-9 items-center gap-2 rounded-control px-3 text-sm font-extrabold text-primary hover:bg-primary-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
                            {{ __('Configure application') }}
                            <svg class="h-3.5 w-3.5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#arrow-right"></use></svg>
                        </a>
                    </div>
                </x-signal.ui.card>
            @endforeach
        </section>

        @if ($projects->hasPreviousPage || $projects->hasNextPage)
            <nav aria-label="{{ __('Application pages') }}" class="mt-6 flex justify-between gap-3">
                @if ($projects->hasPreviousPage)
                    <x-signal.ui.button :href="route('core.workspace.deployer.configuration.index', ['workspace' => $workspace, 'q' => $projects->search, 'page' => $projects->page - 1])">{{ __('Previous page') }}</x-signal.ui.button>
                @else
                    <span></span>
                @endif
                <span class="self-center text-sm text-muted">{{ __('Page :page', ['page' => $projects->page]) }}</span>
                @if ($projects->hasNextPage)
                    <x-signal.ui.button :href="route('core.workspace.deployer.configuration.index', ['workspace' => $workspace, 'q' => $projects->search, 'page' => $projects->page + 1])">{{ __('Next page') }}</x-signal.ui.button>
                @endif
            </nav>
        @endif
    @endif
</x-signal.layouts.platform>
