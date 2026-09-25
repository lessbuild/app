<x-layouts.app>
    @php
        $applicationCreateOpen = request()->query('dialog') === 'create-application';
        $applicationCreateUrl = route('projects.index', ['dialog' => 'create-application']);
    @endphp

    <x-signal.ui.page-header eyebrow="{{ __('Application workspace') }}" icon="view-grid" :title="__('Applications')" :description="__('Organize infrastructure into isolated production, staging, development, and preview environments.')">
        <x-slot:actions>
            <x-signal.ui.button :href="route('builds.index')" variant="secondary">
                {{ __('Deployment history') }}
            </x-signal.ui.button>
            <x-signal.ui.button :href="route('repositories.index')" variant="secondary">
                {{ __('Repositories') }}
            </x-signal.ui.button>
            <x-signal.ui.button
                :href="$applicationCreateUrl"
                data-modal-trigger="application-create-dialog"
                aria-controls="application-create-dialog"
                aria-expanded="{{ $applicationCreateOpen ? 'true' : 'false' }}"
                variant="primary"
            >
                {{ __('New application') }}
            </x-signal.ui.button>
        </x-slot:actions>
    </x-signal.ui.page-header>

    @php
        $environmentCount = $projects->sum('environments_count');
        $previewProjectCount = $projects->where('preview_enabled', true)->count();
        $setupProjectCount = $projects->where('environments_count', 0)->count();
    @endphp

    <x-signal.ui.insights
        id="projects-insights"
        class="mt-6"
        :summary="trans_choice(':count application|:count applications', $projects->count(), ['count' => $projects->count()])"
    >
        <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-signal.ui.stat
                :label="__('Applications')"
                :value="$projects->count()"
                :description="__('Applications in the current workspace.')"
            />
            <x-signal.ui.stat
                :label="__('Environments')"
                :value="$environmentCount"
                :description="__('Production, staging, development, and preview targets.')"
            />
            <x-signal.ui.stat
                :label="__('Preview-enabled')"
                :value="$previewProjectCount"
                :description="__('Applications ready for preview environments.')"
            />
            <x-signal.ui.stat
                :label="__('Needs setup')"
                :value="$setupProjectCount"
                :description="__('Applications without an environment yet.')"
            />
        </dl>
    </x-signal.ui.insights>

    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-muted">
            {{ trans_choice(':count application|:count applications', $projects->count(), ['count' => $projects->count()]) }}
        </p>
        <span class="ui-eyebrow text-[0.65rem]">{{ __('Application overview') }}</span>
    </div>

    <div class="mt-8 grid gap-5 md:grid-cols-2 xl:grid-cols-3">
        @forelse($projects as $project)
            <x-signal.ui.card as="a" tone="interactive" class="group flex min-h-0 flex-col justify-between p-4 sm:min-h-52 sm:p-6" href="{{ route('projects.show', $project) }}" data-project-card>
                <div class="flex min-w-0 flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="ui-avatar ui-avatar-md shrink-0 text-sm" aria-hidden="true">{{ strtoupper(substr($project->name, 0, 1)) }}</span>
                            <h2 class="min-w-0 break-words text-xl font-extrabold text-ink">{{ $project->name }}</h2>
                        </div>
                        <p class="mt-4 line-clamp-2 text-sm leading-6 text-muted">{{ $project->description ?: __('No description') }}</p>
                    </div>
                    <x-signal.ui.badge data-project-environment-count class="shrink-0" tone="neutral">
                        {{ trans_choice(':count environment|:count environments', $project->environments_count, ['count' => $project->environments_count]) }}
                    </x-signal.ui.badge>
                </div>
                <div class="mt-6 flex items-center justify-between gap-3 border-t border-line pt-4">
                    <p class="truncate font-mono text-xs text-muted">{{ $project->slug }}</p>
                    <span class="shrink-0 text-sm font-bold text-ink transition-transform group-hover:translate-x-0.5" aria-hidden="true">→</span>
                </div>
            </x-signal.ui.card>
        @empty
            <div class="md:col-span-2 xl:col-span-3">
                <x-lists.empty :title="__('No applications yet')" :description="__('Create an application to group environments and deployment settings.')">
                    <x-slot:button>
                        <x-signal.ui.button
                            :href="$applicationCreateUrl"
                            data-modal-trigger="application-create-dialog"
                            aria-controls="application-create-dialog"
                            aria-expanded="{{ $applicationCreateOpen ? 'true' : 'false' }}"
                            variant="primary"
                        >
                            {{ __('Create application') }}
                        </x-signal.ui.button>
                    </x-slot:button>
                </x-lists.empty>
            </div>
        @endforelse
    </div>

    <x-scenes.projects.create-dialog
        :templates="$templates"
        :open="$applicationCreateOpen"
    />
</x-layouts.app>
