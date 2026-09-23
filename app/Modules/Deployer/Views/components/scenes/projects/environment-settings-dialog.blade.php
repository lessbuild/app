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
        <label>
            <span class="ui-label">{{ __('Name') }}</span>
            <input name="name" value="{{ old('name', $environment->name) }}" class="ui-input" required>
            <x-forms.errors name="name" />
        </label>
        <label>
            <span class="ui-label">{{ __('Type') }}</span>
            <select name="type" class="ui-input">
                @foreach (\App\Modules\Deployer\Models\Environment::TYPES as $type)
                    <option value="{{ $type }}" @selected(old('type', $environment->type) === $type)>{{ ucfirst($type) }}</option>
                @endforeach
            </select>
            <x-forms.errors name="type" />
        </label>
        <label>
            <span class="ui-label">{{ __('Branch') }}</span>
            <input name="branch" value="{{ old('branch', $environment->branch) }}" class="ui-input" required>
            <x-forms.errors name="branch" />
        </label>
        <label>
            <span class="ui-label">{{ __('Runtime') }}</span>
            <select name="runtime_type" class="ui-input">
                @foreach (\App\Modules\Deployer\Models\Environment::RUNTIME_TYPES as $runtime)
                    <option value="{{ $runtime }}" @selected(old('runtime_type', $environment->runtime_type ?: 'php') === $runtime)>{{ ucfirst($runtime) }}</option>
                @endforeach
            </select>
            <x-forms.errors name="runtime_type" />
        </label>
        <label>
            <span class="ui-label">{{ __('Runtime version') }}</span>
            <input name="runtime_version" value="{{ old('runtime_version', $environment->runtime_version) }}" placeholder="20" class="ui-input">
            <x-forms.errors name="runtime_version" />
        </label>
        <label class="sm:col-span-2">
            <span class="ui-label">{{ __('Build command') }}</span>
            <input name="build_command" value="{{ old('build_command', $environment->build_command) }}" placeholder="npm run build" class="ui-input font-mono">
            <x-forms.errors name="build_command" />
        </label>
        <label class="sm:col-span-2">
            <span class="ui-label">{{ __('Start command') }}</span>
            <input name="start_command" value="{{ old('start_command', $environment->start_command) }}" placeholder="npm start" class="ui-input font-mono">
            <x-forms.errors name="start_command" />
        </label>
        <label>
            <span class="ui-label">{{ __('Application port') }}</span>
            <input type="number" min="1" max="65535" name="container_port" value="{{ old('container_port', $environment->container_port) }}" placeholder="3000" class="ui-input">
            <x-forms.errors name="container_port" />
        </label>
        <label>
            <span class="ui-label">{{ __('Dockerfile path') }}</span>
            <input name="dockerfile_path" value="{{ old('dockerfile_path', $environment->dockerfile_path) }}" placeholder="Dockerfile" class="ui-input font-mono">
            <x-forms.errors name="dockerfile_path" />
        </label>
        <label>
            <span class="ui-label">{{ __('Server') }}</span>
            <select name="server_id" class="ui-input">
                <option value="">{{ __('None') }}</option>
                @foreach ($servers as $server)
                    <option value="{{ $server->id }}" @selected(old('server_id', $environment->server_id) == $server->id)>{{ $server->label }}</option>
                @endforeach
            </select>
            <x-forms.errors name="server_id" />
        </label>
        <label class="sm:col-span-2">
            <span class="ui-label">{{ __('Website') }}</span>
            <select name="website_id" class="ui-input">
                <option value="">{{ __('None') }}</option>
                @foreach ($websites as $website)
                    <option value="{{ $website->id }}" @selected(old('website_id', $environment->website_id) == $website->id)>{{ $website->name }}</option>
                @endforeach
            </select>
            <x-forms.errors name="website_id" />
        </label>
        @if ($featureAccess['hibernation'] ?? false)
            <label class="sm:col-span-2">
                <span class="ui-label">{{ __('Hibernate after inactivity') }}</span>
                <select name="hibernate_after_minutes" class="ui-input">
                    <option value="">{{ __('Never') }}</option>
                    @foreach ([5, 15, 30, 60, 120, 1440] as $minutes)
                        <option value="{{ $minutes }}" @selected((string) old('hibernate_after_minutes', $environment->hibernate_after_minutes) === (string) $minutes)>{{ trans_choice(':count minute|:count minutes', $minutes, ['count' => $minutes]) }}</option>
                    @endforeach
                </select>
                <x-forms.errors name="hibernate_after_minutes" />
            </label>
        @endif
        @if ($featureAccess['monitoring'] ?? false)
            <label class="sm:col-span-2">
                <span class="ui-label">{{ __('Observe after deployment') }}</span>
                <select name="post_deployment_observation_minutes" class="ui-input">
                    <option value="">{{ __('Disabled') }}</option>
                    @foreach (\App\Modules\Deployer\Models\Environment::POST_DEPLOYMENT_OBSERVATION_MINUTES as $minutes)
                        <option value="{{ $minutes }}" @selected((string) old('post_deployment_observation_minutes', $environment->post_deployment_observation_minutes) === (string) $minutes)>{{ __('For :minutes minutes', ['minutes' => $minutes]) }}</option>
                    @endforeach
                </select>
                <span class="ui-help">{{ __('Check the deployed website for this window and keep the revision-linked result available for troubleshooting.') }}</span>
                <x-forms.errors name="post_deployment_observation_minutes" />
            </label>
        @endif
        <label class="flex items-center gap-2">
            <input type="hidden" name="is_protected" value="0">
            <input class="ui-check" type="checkbox" name="is_protected" value="1" @checked((bool) old('is_protected', $environment->is_protected))>
            <span class="text-sm text-ink">{{ __('Protect') }}</span>
        </label>
        <label class="flex items-center gap-2">
            <input type="hidden" name="requires_deployment_approval" value="0">
            <input class="ui-check" type="checkbox" name="requires_deployment_approval" value="1" @checked((bool) old('requires_deployment_approval', $environment->requires_deployment_approval))>
            <span class="text-sm text-ink">{{ __('Require approval') }}</span>
        </label>
        <x-forms.errors name="is_protected" />
        <x-forms.errors name="requires_deployment_approval" />
        <x-ui.button type="submit" variant="primary" class="sm:col-span-2">{{ __('Save settings') }}</x-ui.button>
    </form>
</x-dialogs.modal>
