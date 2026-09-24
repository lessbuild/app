<x-signal.layouts.platform
    :title="__('Validate project handover')"
    :description="__('Check a project handover manifest against this workspace before making changes.')"
    :navigation="[]"
    :account-user="$user"
    :current-workspace="$workspace"
    :workspaces="$workspaces"
    :context-projects="$contextProjects"
>
    <x-signal.ui.page-header
        :eyebrow="$workspace->name.' · '.__('Projects')"
        :title="__('Validate project handover')"
        :description="__('Review product access, plan availability, resource mappings, and connection requirements for this destination workspace.')"
    >
        <x-slot:actions>
            <x-signal.ui.button :href="route('core.projects.index', $workspace)">{{ __('Back to projects') }}</x-signal.ui.button>
        </x-slot:actions>
    </x-signal.ui.page-header>

    <x-signal.ui.panel class="mx-auto max-w-3xl p-5 sm:p-7">
        <div class="mb-6">
            <h2 class="text-lg font-extrabold text-ink">{{ __('Choose a handover manifest') }}</h2>
            <p class="mt-2 text-sm leading-6 text-muted">{{ __('Upload a Buildpusher project manifest exported by a workspace owner or admin. The report checks current destination access and limits; it does not create projects, change ownership, connect resources, or move subscriptions.') }}</p>
        </div>
        <form method="POST" action="{{ route('core.projects.handover.validate', $workspace) }}" enctype="multipart/form-data" class="grid gap-5">
            @csrf
            <x-signal.ui.input-field
                name="manifest"
                type="file"
                accept="application/json,.json"
                :label="__('Project manifest (JSON)')"
                :description="__('Maximum file size: 2 MB. Credentials, secrets, metadata, and environment values are not accepted.')"
                required
            />
            <div class="flex flex-wrap justify-end gap-3 border-t border-line pt-5">
                <x-signal.ui.button :href="route('core.projects.index', $workspace)">{{ __('Cancel') }}</x-signal.ui.button>
                <x-signal.ui.button variant="primary" type="submit">{{ __('Run dry-run validation') }}</x-signal.ui.button>
            </div>
        </form>
    </x-signal.ui.panel>
</x-signal.layouts.platform>
