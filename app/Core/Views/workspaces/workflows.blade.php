<x-signal.layouts.platform
    :title="__('Workflow activity')"
    :description="__('Recent deployment and connected-app activity in this workspace.')"
    :navigation="[]"
    :account-user="$user"
    :current-workspace="$workspace"
    :workspaces="$workspaces"
    :context-projects="$contextProjects"
>
    <x-signal.ui.page-header
        :eyebrow="$workspace->name"
        :title="__('Workflow activity')"
        :description="__('Return to recent deployments and incident updates moving between your connected applications.')"
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
                <h2 id="workspace-workflows-heading" class="mt-1 text-lg font-extrabold text-ink">{{ __('Recent product activity') }}</h2>
            </div>
            <p class="max-w-2xl text-sm leading-6 text-muted">{{ __('Deployment status and each connected-app result are saved by the product that owns them. Retry actions apply to one failed connection step and recheck current access.') }}</p>
        </div>

        @if ($unavailableProducts->isNotEmpty())
            <x-signal.ui.alert tone="warning" class="mb-4" role="status">
                {{ __('Recent activity from :products is temporarily unavailable. Open that product to check its current status.', ['products' => $unavailableProducts->join(', ')]) }}
            </x-signal.ui.alert>
        @endif

        @if ($workflowRuns->isEmpty())
            <x-signal.ui.empty-state
                :title="$unavailableProducts->isNotEmpty() ? __('Recent activity could not be loaded') : __('No recent product activity yet')"
                :description="$unavailableProducts->isNotEmpty() ? __('The available app records are temporarily unavailable. This page will show new activity after those products respond again.') : __('When a deployment or connected app update is recorded, its progress will be available here after you leave the product screen.')"
                :icon="$unavailableProducts->isNotEmpty() ? 'clock' : 'link'"
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

        <p class="mt-4 text-xs leading-5 text-subtle">{{ __('Showing up to 30 recent items from projects and app resources you can currently access. Product records remain authoritative; open an item for full details and permitted actions.') }}</p>
    </section>
</x-signal.layouts.platform>
