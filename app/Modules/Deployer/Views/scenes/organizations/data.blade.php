<x-layouts.app>
    <div class="mx-auto max-w-4xl space-y-8">
        <x-signal.ui.page-header
            eyebrow="{{ $organization->name }}"
            icon="shield"
            :title="__('Data & privacy')"
            :description="__('Export the durable Deployer workspace records that you manage in a documented, streaming format.')"
        >
            <x-slot:actions>
                <x-signal.ui.button href="{{ route('organizations.index') }}" variant="secondary">
                    {{ __('Workspace settings') }}
                </x-signal.ui.button>
            </x-slot:actions>
        </x-signal.ui.page-header>

        <x-signal.ui.panel as="section" class="space-y-5 p-6" aria-labelledby="deployer-export-heading">
            <div>
                <p class="ui-eyebrow">{{ __('Workspace export') }}</p>
                <h2 id="deployer-export-heading" class="mt-1 text-lg font-extrabold text-ink">
                    {{ __('Export Deployer workspace data') }}
                </h2>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-muted">
                    {{ __('The newline-delimited JSON export includes workspace membership, invitations, projects, environment settings, deployment history, connected providers and infrastructure metadata, preview deployments, scheduled automation, status pages and incidents, and backup and health history. Records stream from the Deployer database, so large workspaces do not need to fit in memory.') }}
                </p>
            </div>

            <x-signal.ui.alert tone="info" role="note">
                {{ __('Access tokens, SSH keys, environment values, process and task commands, private deployment payloads, logs, backup credentials and contents, alert endpoints and signing secrets, and invitation or subscriber tokens are excluded. The export includes configuration metadata and record relationships so workspace records can be reviewed and rebuilt without disclosing credentials.') }}
            </x-signal.ui.alert>

            <div class="flex flex-wrap items-center gap-3">
                <x-signal.ui.button :href="route('organizations.data.export')" variant="primary">
                    {{ __('Download workspace export') }}
                </x-signal.ui.button>
                <p class="text-xs text-muted">
                    {{ __('Available to workspace owners and administrators. Downloading does not change workspace data.') }}
                </p>
            </div>
        </x-signal.ui.panel>
    </div>
</x-layouts.app>
