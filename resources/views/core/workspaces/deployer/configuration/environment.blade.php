@php
    $environmentTypes = \App\Modules\Deployer\Models\Environment::TYPES;
    $replicaOptions = range(1, 20);
    $hibernationOptions = [5, 15, 30, 60, 120, 1440];
    $observationOptions = \App\Modules\Deployer\Models\Environment::POST_DEPLOYMENT_OBSERVATION_MINUTES;
@endphp

<x-signal.layouts.platform
    :title="$snapshot->environmentName"
    :description="__('Environment settings for :environment in :project.', ['environment' => $snapshot->environmentName, 'project' => $snapshot->projectName])"
    :navigation="[]"
    :account-user="$user"
    :current-workspace="$workspace"
    :workspaces="$workspaces"
    :context-projects="$contextProjects"
    :current-project="$project"
>
    <x-signal.ui.page-header
        :eyebrow="$snapshot->projectName.' · '.__('Deployer environment')"
        :title="$snapshot->environmentName"
        :description="__('Edit safe environment settings for the exactly mapped Deployer resource.')"
    >
        <x-slot:actions>
            <x-signal.ui.button :href="route('core.workspace.deployer.configuration.projects.show', [$workspace, $project])">{{ __('Application settings') }}</x-signal.ui.button>
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

    <x-signal.ui.card class="p-5 sm:p-6">
        <form method="POST" action="{{ route('core.workspace.deployer.configuration.projects.environments.update', [$workspace, $project, $snapshot->environmentId]) }}" class="grid gap-4 sm:grid-cols-2">
            @csrf
            @method('PATCH')

            <label>
                <span class="ui-label">{{ __('Environment name') }}</span>
                <input class="ui-input mt-1" type="text" name="name" value="{{ old('name', $snapshot->environmentName) }}" maxlength="100" required>
                @error('name')<span class="mt-1 block text-sm text-danger">{{ $message }}</span>@enderror
            </label>
            <label>
                <span class="ui-label">{{ __('Environment type') }}</span>
                <select class="ui-input mt-1" name="type" required>
                    @foreach ($environmentTypes as $type)
                        <option value="{{ $type }}" @selected(old('type', $snapshot->environmentType) === $type)>{{ str($type)->headline() }}</option>
                    @endforeach
                </select>
                @error('type')<span class="mt-1 block text-sm text-danger">{{ $message }}</span>@enderror
            </label>
            <label class="sm:col-span-2">
                <span class="ui-label">{{ __('Deployment branch') }}</span>
                <input class="ui-input mt-1" type="text" name="branch" value="{{ old('branch', $snapshot->branch) }}" maxlength="255" required>
                @error('branch')<span class="mt-1 block text-sm text-danger">{{ $message }}</span>@enderror
            </label>
            <label>
                <span class="ui-label">{{ __('Minimum replicas') }}</span>
                <select class="ui-input mt-1" name="minimum_replicas" required>
                    @foreach ($replicaOptions as $replicas)
                        <option value="{{ $replicas }}" @selected((int) old('minimum_replicas', $snapshot->minimumReplicas) === $replicas)>{{ $replicas }}</option>
                    @endforeach
                </select>
                @error('minimum_replicas')<span class="mt-1 block text-sm text-danger">{{ $message }}</span>@enderror
            </label>
            <label>
                <span class="ui-label">{{ __('Maximum replicas') }}</span>
                <select class="ui-input mt-1" name="maximum_replicas" required>
                    @foreach ($replicaOptions as $replicas)
                        <option value="{{ $replicas }}" @selected((int) old('maximum_replicas', $snapshot->maximumReplicas) === $replicas)>{{ $replicas }}</option>
                    @endforeach
                </select>
                @error('maximum_replicas')<span class="mt-1 block text-sm text-danger">{{ $message }}</span>@enderror
            </label>
            @error('maximum_replicas')
                @if ($errors->has('minimum_replicas'))<p class="text-sm text-danger sm:col-span-2">{{ $message }}</p>@endif
            @enderror
            <label>
                <span class="ui-label">{{ __('Idle hibernation') }}</span>
                <select class="ui-input mt-1" name="hibernate_after_minutes">
                    <option value="" @selected(old('hibernate_after_minutes', $snapshot->hibernateAfterMinutes) === null)>{{ __('Off') }}</option>
                    @foreach ($hibernationOptions as $minutes)
                        <option value="{{ $minutes }}" @selected((string) old('hibernate_after_minutes', $snapshot->hibernateAfterMinutes) === (string) $minutes)>{{ __(':minutes minutes', ['minutes' => $minutes]) }}</option>
                    @endforeach
                </select>
                @error('hibernate_after_minutes')<span class="mt-1 block text-sm text-danger">{{ $message }}</span>@enderror
            </label>
            <label>
                <span class="ui-label">{{ __('Post-deployment observation') }}</span>
                <select class="ui-input mt-1" name="post_deployment_observation_minutes">
                    <option value="" @selected(old('post_deployment_observation_minutes', $snapshot->postDeploymentObservationMinutes) === null)>{{ __('Off') }}</option>
                    @foreach ($observationOptions as $minutes)
                        <option value="{{ $minutes }}" @selected((string) old('post_deployment_observation_minutes', $snapshot->postDeploymentObservationMinutes) === (string) $minutes)>{{ __(':minutes minutes', ['minutes' => $minutes]) }}</option>
                    @endforeach
                </select>
                @error('post_deployment_observation_minutes')<span class="mt-1 block text-sm text-danger">{{ $message }}</span>@enderror
            </label>

            <div class="rounded-control border border-line bg-surface-muted p-4 text-sm leading-6 text-muted sm:col-span-2">
                <p><span class="font-bold text-ink">{{ __('Runtime') }}:</span> {{ str($snapshot->runtimeType)->headline() }}{{ $snapshot->runtimeVersion ? ' · '.$snapshot->runtimeVersion : '' }}</p>
                <p class="mt-1">{{ __('Build and start commands, server and website placement, protection and approval controls, variables, secrets, processes, resources, and full release controls stay in Deployer.') }}</p>
                <p class="mt-1">{{ __('Plan entitlements are rechecked when scaling, hibernation, or observation settings change. Saving these values does not start a deployment or contact a provider.') }}</p>
            </div>

            <div class="sm:col-span-2">
                <x-signal.ui.button variant="primary" type="submit">{{ __('Save environment settings') }}</x-signal.ui.button>
            </div>
        </form>
    </x-signal.ui.card>
</x-signal.layouts.platform>
