@props([
    'environment',
    'servers',
    'websites',
    'featureAccess' => [],
    'open' => false,
])

<x-dialogs.modal
    id="environment-settings-dialog-{{ $environment->id }}"
    :title="__('Environment settings')"
    :description="__('Update runtime, source, placement and deployment protection for this environment.')"
    :open="$open"
>
    <form method="POST" action="{{ route('environments.update', ['environment' => $environment, 'dialog' => 'edit-environment-settings-'.$environment->id]) }}" class="grid gap-4 sm:grid-cols-2">
        @csrf
        @method('PATCH')
        <input type="hidden" name="_environment_id" value="{{ $environment->id }}">
        <input type="hidden" name="_environment_panel" value="settings">
        <x-signal.ui.input-field :id="'environment-settings-'.$environment->id.'-name'" name="name" :label="__('Name')" :value="$environment->name" required />
        <x-signal.ui.select-field :id="'environment-settings-'.$environment->id.'-type'" name="type" :label="__('Type')">
            @foreach (\App\Modules\Deployer\Models\Environment::TYPES as $type)
                <option value="{{ $type }}" @selected(old('type', $environment->type) === $type)>{{ ucfirst($type) }}</option>
            @endforeach
        </x-signal.ui.select-field>
        <x-signal.ui.input-field :id="'environment-settings-'.$environment->id.'-branch'" name="branch" :label="__('Branch')" :value="$environment->branch" required />
        <x-signal.ui.select-field :id="'environment-settings-'.$environment->id.'-runtime-type'" name="runtime_type" :label="__('Runtime')">
            @foreach (\App\Modules\Deployer\Models\Environment::RUNTIME_TYPES as $runtime)
                <option value="{{ $runtime }}" @selected(old('runtime_type', $environment->runtime_type ?: 'php') === $runtime)>{{ ucfirst($runtime) }}</option>
            @endforeach
        </x-signal.ui.select-field>
        <x-signal.ui.input-field :id="'environment-settings-'.$environment->id.'-runtime-version'" name="runtime_version" :label="__('Runtime version')" :value="$environment->runtime_version" placeholder="20" />
        <x-signal.ui.input-field :id="'environment-settings-'.$environment->id.'-build-command'" name="build_command" :label="__('Build command')" :value="$environment->build_command" placeholder="npm run build" field-class="sm:col-span-2" class="font-mono" />
        <x-signal.ui.input-field :id="'environment-settings-'.$environment->id.'-start-command'" name="start_command" :label="__('Start command')" :value="$environment->start_command" placeholder="npm start" field-class="sm:col-span-2" class="font-mono" />
        <x-signal.ui.input-field :id="'environment-settings-'.$environment->id.'-container-port'" name="container_port" :label="__('Application port')" :value="$environment->container_port" type="number" min="1" max="65535" placeholder="3000" />
        <x-signal.ui.input-field :id="'environment-settings-'.$environment->id.'-dockerfile-path'" name="dockerfile_path" :label="__('Dockerfile path')" :value="$environment->dockerfile_path" placeholder="Dockerfile" class="font-mono" />
        <x-signal.ui.select-field :id="'environment-settings-'.$environment->id.'-server-id'" name="server_id" :label="__('Server')">
            <option value="">{{ __('None') }}</option>
            @foreach ($servers as $server)
                <option value="{{ $server->id }}" @selected(old('server_id', $environment->server_id) == $server->id)>{{ $server->label }}</option>
            @endforeach
        </x-signal.ui.select-field>
        <x-signal.ui.select-field :id="'environment-settings-'.$environment->id.'-website-id'" name="website_id" :label="__('Website')" field-class="sm:col-span-2">
            <option value="">{{ __('None') }}</option>
            @foreach ($websites as $website)
                <option value="{{ $website->id }}" @selected(old('website_id', $environment->website_id) == $website->id)>{{ $website->name }}</option>
            @endforeach
        </x-signal.ui.select-field>
        @if ($featureAccess['hibernation'] ?? false)
            <x-signal.ui.select-field :id="'environment-settings-'.$environment->id.'-hibernate-after-minutes'" name="hibernate_after_minutes" :label="__('Hibernate after inactivity')" field-class="sm:col-span-2">
                    <option value="">{{ __('Never') }}</option>
                    @foreach ([5, 15, 30, 60, 120, 1440] as $minutes)
                        <option value="{{ $minutes }}" @selected((string) old('hibernate_after_minutes', $environment->hibernate_after_minutes) === (string) $minutes)>{{ trans_choice(':count minute|:count minutes', $minutes, ['count' => $minutes]) }}</option>
                    @endforeach
            </x-signal.ui.select-field>
        @endif
        @if ($featureAccess['monitoring'] ?? false)
            <x-signal.ui.select-field :id="'environment-settings-'.$environment->id.'-post-deployment-observation-minutes'" name="post_deployment_observation_minutes" :label="__('Observe after deployment')" :description="__('Check the deployed website for this window and keep the revision-linked result available for troubleshooting.')" field-class="sm:col-span-2">
                    <option value="">{{ __('Disabled') }}</option>
                    @foreach (\App\Modules\Deployer\Models\Environment::POST_DEPLOYMENT_OBSERVATION_MINUTES as $minutes)
                        <option value="{{ $minutes }}" @selected((string) old('post_deployment_observation_minutes', $environment->post_deployment_observation_minutes) === (string) $minutes)>{{ __('For :minutes minutes', ['minutes' => $minutes]) }}</option>
                    @endforeach
            </x-signal.ui.select-field>
        @endif
        <x-signal.ui.checkbox :id="'environment-settings-'.$environment->id.'-is-protected'" name="is_protected" :checked="(bool) old('is_protected', $environment->is_protected)" unchecked-value="0">{{ __('Protect') }}</x-signal.ui.checkbox>
        <x-signal.ui.checkbox :id="'environment-settings-'.$environment->id.'-requires-deployment-approval'" name="requires_deployment_approval" :checked="(bool) old('requires_deployment_approval', $environment->requires_deployment_approval)" unchecked-value="0">{{ __('Require approval') }}</x-signal.ui.checkbox>
        <x-signal.ui.button type="submit" variant="primary" class="sm:col-span-2">{{ __('Save settings') }}</x-signal.ui.button>
    </form>
</x-dialogs.modal>
