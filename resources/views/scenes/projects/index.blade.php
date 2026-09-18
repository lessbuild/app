<x-layouts.app>
    <x-layouts.partials.heading icon="view-grid" :title="__('Applications')" :description="__('Organize infrastructure into isolated production, staging, development, and preview environments.')">
        <x-slot:buttons>
            <x-ui.button :href="route('builds.index')" variant="secondary">
                {{ __('Deployment history') }}
            </x-ui.button>
            <x-ui.button :href="route('repositories.index')" variant="secondary">
                {{ __('Repositories') }}
            </x-ui.button>
            <x-ui.button :href="route('projects.create')" variant="primary">
                {{ __('New application') }}
            </x-ui.button>
        </x-slot:buttons>
    </x-layouts.partials.heading>

    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-secondary">
            {{ trans_choice(':count application|:count applications', $projects->count(), ['count' => $projects->count()]) }}
        </p>
        <span class="text-xs font-semibold uppercase tracking-wide text-secondary">{{ __('Application overview') }}</span>
    </div>

    <div class="mt-8 grid gap-5 md:grid-cols-2 xl:grid-cols-3">
        @forelse($projects as $project)
            <a href="{{ route('projects.show', $project) }}" data-project-card class="ui-card ui-card--interactive group flex min-h-52 flex-col justify-between p-6">
                <div class="flex min-w-0 flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-secondary text-sm font-black text-ternary" aria-hidden="true">{{ strtoupper(substr($project->name, 0, 1)) }}</span>
                            <h2 class="min-w-0 break-words text-xl font-black text-primary">{{ $project->name }}</h2>
                        </div>
                        <p class="mt-4 line-clamp-2 text-sm leading-6 text-secondary">{{ $project->description ?: __('No description') }}</p>
                    </div>
                    <x-ui.badge data-project-environment-count class="shrink-0" tone="neutral">
                        {{ trans_choice(':count environment|:count environments', $project->environments_count, ['count' => $project->environments_count]) }}
                    </x-ui.badge>
                </div>
                <div class="mt-6 flex items-center justify-between gap-3 border-t border-primary pt-4">
                    <p class="truncate font-mono text-xs text-secondary">{{ $project->slug }}</p>
                    <span class="shrink-0 text-sm font-bold text-ternary transition-transform group-hover:translate-x-0.5" aria-hidden="true">→</span>
                </div>
            </a>
        @empty
            <div class="md:col-span-2 xl:col-span-3">
                <x-lists.empty :title="__('No applications yet')" :description="__('Create an application to group environments and deployment settings.')">
                    <x-slot:button>
                        <x-ui.button :href="route('projects.create')" variant="primary">
                            {{ __('Create application') }}
                        </x-ui.button>
                    </x-slot:button>
                </x-lists.empty>
            </div>
        @endforelse
    </div>
</x-layouts.app>
