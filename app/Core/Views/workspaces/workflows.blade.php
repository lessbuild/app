<x-signal.layouts.platform
    :title="__('Workflow activity')"
    :description="__('Recent delivery progress for connected applications in this workspace.')"
    :navigation="[]"
    :account-user="$user"
    :current-workspace="$workspace"
    :workspaces="$workspaces"
    :context-projects="$contextProjects"
>
    <x-signal.ui.page-header
        :eyebrow="$workspace->name"
        :title="__('Workflow activity')"
        :description="__('Return to recent deployment and incident updates moving between your connected applications.')"
    >
        <x-slot:actions>
            <x-signal.ui.button :href="route('core.projects.index', $workspace)">
                {{ __('All projects') }}
            </x-signal.ui.button>
            <x-signal.ui.button variant="primary" :href="route('core.workspace.dashboard', $workspace)">
                {{ __('Workspace overview') }}
            </x-signal.ui.button>
        </x-slot:actions>
    </x-signal.ui.page-header>

    <section aria-labelledby="workspace-workflows-heading" class="mt-8">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="ui-eyebrow">{{ __('Shared project activity') }}</p>
                <h2 id="workspace-workflows-heading" class="mt-1 text-lg font-extrabold text-ink">{{ __('Connected workflow runs') }}</h2>
            </div>
            <p class="max-w-2xl text-sm leading-6 text-muted">{{ __('Source success and each receiving-app result are saved separately. Retry actions apply to one failed step and recheck current access.') }}</p>
        </div>

        @if ($workflowRuns->isEmpty())
            <x-signal.ui.empty-state
                :title="__('No connected workflow activity yet')"
                :description="__('When a connected app records a deployment or incident update, its progress will be available here after you leave the project.')"
                icon="link"
            >
                <x-slot:action>
                    <x-signal.ui.button variant="primary" :href="route('core.projects.index', $workspace)">
                        {{ __('Open projects') }}
                    </x-signal.ui.button>
                </x-slot:action>
            </x-signal.ui.empty-state>
        @else
            <div class="grid gap-4">
                @foreach ($workflowRuns as $workflowRun)
                    <x-signal.ui.workflow-run :run="$workflowRun" />
                @endforeach
            </div>
        @endif

        <p class="mt-4 text-xs leading-5 text-subtle">{{ __('Showing up to 30 recent runs from projects and app resources you can currently access. Full deployment, export, and job details remain in their product screens.') }}</p>
    </section>
</x-signal.layouts.platform>
