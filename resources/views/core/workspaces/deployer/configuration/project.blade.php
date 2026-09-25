<x-signal.layouts.platform
    :title="$snapshot->projectName"
    :description="__('Deployer preview settings for :project.', ['project' => $snapshot->projectName])"
    :navigation="[]"
    :account-user="$user"
    :current-workspace="$workspace"
    :workspaces="$workspaces"
    :context-projects="$contextProjects"
    :current-project="$project"
>
    <x-signal.ui.page-header
        :eyebrow="$workspace->name.' · '.__('Deployer configuration')"
        :title="$snapshot->projectName"
        :description="__('Preview policy and mapped environment settings for this application.')"
    >
        <x-slot:actions>
            <x-signal.ui.button :href="route('core.workspace.deployer.configuration.index', $workspace)">{{ __('All applications') }}</x-signal.ui.button>
            <x-signal.ui.button :href="route('core.projects.show', [$workspace, $project])">{{ __('Shared project') }}</x-signal.ui.button>
        </x-slot:actions>
    </x-signal.ui.page-header>

    @if (session('status'))
        <x-signal.ui.alert tone="success" class="mb-6" role="status">{{ session('status') }}</x-signal.ui.alert>
    @endif
    @if ($errors->any())
        <x-signal.ui.alert tone="warning" class="mb-6" role="alert">
            <ul class="list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </x-signal.ui.alert>
    @endif

    <section aria-labelledby="preview-settings-heading" class="mb-8">
        <x-signal.ui.card class="p-5 sm:p-6">
            <div class="mb-5">
                <p class="ui-eyebrow">{{ __('Project settings') }}</p>
                <h2 id="preview-settings-heading" class="mt-1 text-lg font-extrabold text-ink">{{ __('Preview policy') }}</h2>
                <p class="mt-1 text-sm leading-6 text-muted">{{ __('The Deployer plan is checked when previews are enabled. Saving settings does not start a deployment or contact a provider.') }}</p>
            </div>
            <form method="POST" action="{{ route('core.workspace.deployer.configuration.projects.previews.update', [$workspace, $project]) }}" class="grid gap-4 sm:grid-cols-2">
                @csrf
                @method('PATCH')
                <label class="flex items-center gap-3 rounded-control border border-line bg-surface-muted p-3 sm:col-span-2">
                    <input type="checkbox" name="preview_enabled" value="1" @checked(old('preview_enabled', $snapshot->previewEnabled)) class="h-4 w-4 rounded border-line text-primary focus:ring-focus">
                    <span>
                        <span class="block text-sm font-bold text-ink">{{ __('Enable preview environments') }}</span>
                        <span class="mt-1 block text-xs text-muted">{{ __('Preview policy changes apply to future preview environments.') }}</span>
                    </span>
                </label>
                @error('preview_enabled')<p class="text-sm text-danger sm:col-span-2">{{ $message }}</p>@enderror
                <label>
                    <span class="ui-label">{{ __('Preview hostname') }}</span>
                    <input class="ui-input mt-1" type="text" name="preview_domain" value="{{ old('preview_domain', $snapshot->previewDomain) }}" maxlength="200" autocomplete="off">
                    @error('preview_domain')<span class="mt-1 block text-sm text-danger">{{ $message }}</span>@enderror
                </label>
                <label>
                    <span class="ui-label">{{ __('Preview lifetime (hours)') }}</span>
                    <input class="ui-input mt-1" type="number" name="preview_ttl_hours" min="1" max="720" value="{{ old('preview_ttl_hours', $snapshot->previewTtlHours) }}">
                    @error('preview_ttl_hours')<span class="mt-1 block text-sm text-danger">{{ $message }}</span>@enderror
                </label>
                <div class="sm:col-span-2">
                    <x-signal.ui.button variant="primary" type="submit">{{ __('Save preview policy') }}</x-signal.ui.button>
                </div>
            </form>
        </x-signal.ui.card>
    </section>

    <section aria-labelledby="mapped-environments-heading">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="ui-eyebrow">{{ __('Mapped Deployer resources') }}</p>
                <h2 id="mapped-environments-heading" class="mt-1 text-lg font-extrabold text-ink">{{ __('Environments') }}</h2>
                <p class="mt-1 text-sm text-muted">{{ __('Only environments with an active, unique Core mapping and native edit access are listed.') }}</p>
            </div>
        </div>

        <x-signal.ui.card class="mb-4 p-4">
            <form method="GET" action="{{ route('core.workspace.deployer.configuration.projects.show', [$workspace, $project]) }}" class="flex flex-wrap items-end gap-3">
                <label class="min-w-56 flex-1">
                    <span class="ui-label">{{ __('Search environments') }}</span>
                    <input class="ui-input mt-1" type="search" name="environment_q" value="{{ $snapshot->environmentSearch }}" maxlength="100" placeholder="{{ __('Environment name') }}">
                </label>
                <x-signal.ui.button type="submit">{{ __('Search') }}</x-signal.ui.button>
                @if ($snapshot->environmentSearch)
                    <x-signal.ui.button :href="route('core.workspace.deployer.configuration.projects.show', [$workspace, $project])">{{ __('Clear') }}</x-signal.ui.button>
                @endif
            </form>
        </x-signal.ui.card>

        @if ($snapshot->environments === [])
            <x-signal.ui.empty-state
                :title="$snapshot->environmentSearch ? __('No matching environments') : __('No mapped environments to configure')"
                :description="__('Connect an existing Deployer environment to this shared project before managing its settings here.')"
                icon="server"
            />
        @else
            <div class="grid gap-3 lg:grid-cols-2">
                @foreach ($snapshot->environments as $environment)
                    <x-signal.ui.card class="p-4 sm:p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 class="font-extrabold text-ink">{{ $environment->environmentName }}</h3>
                                <p class="mt-1 text-sm text-muted">{{ str($environment->environmentType)->headline() }} · {{ str($environment->runtimeType)->headline() }} · {{ $environment->branch }}</p>
                            </div>
                            <a href="{{ route('core.workspace.deployer.configuration.projects.environments.show', [$workspace, $project, $environment->environmentId]) }}" class="inline-flex min-h-9 items-center rounded-control px-3 text-sm font-extrabold text-primary hover:bg-primary-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
                                {{ __('Configure') }}
                            </a>
                        </div>
                        <p class="mt-3 text-xs text-muted">{{ __('Replicas: :minimum–:maximum · Hibernation: :hibernation', ['minimum' => $environment->minimumReplicas, 'maximum' => $environment->maximumReplicas, 'hibernation' => $environment->hibernateAfterMinutes ? __(':minutes minutes', ['minutes' => $environment->hibernateAfterMinutes]) : __('off')]) }}</p>
                    </x-signal.ui.card>
                @endforeach
            </div>

            @if ($snapshot->hasPreviousEnvironmentPage || $snapshot->hasNextEnvironmentPage)
                <nav aria-label="{{ __('Environment pages') }}" class="mt-6 flex justify-between gap-3">
                    @if ($snapshot->hasPreviousEnvironmentPage)
                        <x-signal.ui.button :href="route('core.workspace.deployer.configuration.projects.show', ['workspace' => $workspace, 'project' => $project, 'environment_q' => $snapshot->environmentSearch, 'environment_page' => $snapshot->environmentPage - 1])">{{ __('Previous page') }}</x-signal.ui.button>
                    @else
                        <span></span>
                    @endif
                    <span class="self-center text-sm text-muted">{{ __('Page :page', ['page' => $snapshot->environmentPage]) }}</span>
                    @if ($snapshot->hasNextEnvironmentPage)
                        <x-signal.ui.button :href="route('core.workspace.deployer.configuration.projects.show', ['workspace' => $workspace, 'project' => $project, 'environment_q' => $snapshot->environmentSearch, 'environment_page' => $snapshot->environmentPage + 1])">{{ __('Next page') }}</x-signal.ui.button>
                    @endif
                </nav>
            @endif
        @endif
    </section>
</x-signal.layouts.platform>
