<x-signal.layouts.platform :title="__('Service objectives')" :description="__('Set availability and latency targets for mapped Monitor environments.')" :navigation="[]" :account-user="$user" :current-workspace="$workspace" :workspaces="$workspaces" :context-projects="$contextProjects">
    <x-signal.ui.page-header :eyebrow="$workspace->name" :title="__('Service objectives')" :description="__('Manage reliability targets for environments mapped to this Core workspace. Use Monitor for SLO reports and exports.')">
        <x-slot:actions>
            <x-signal.ui.button :href="route('core.workspace.monitor.alerts', $workspace)" variant="secondary">{{ __('Alert rules') }}</x-signal.ui.button>
            <x-signal.ui.button :href="route('core.workspace.monitor.configuration.index', $workspace)" variant="secondary">{{ __('Monitor configuration') }}</x-signal.ui.button>
        </x-slot:actions>
    </x-signal.ui.page-header>

    @if (session('status')) <x-signal.ui.alert tone="success" class="mt-5">{{ session('status') }}</x-signal.ui.alert> @endif
    @if ($errors->any()) <x-signal.ui.alert tone="danger" class="mt-5">{{ $errors->first() }}</x-signal.ui.alert> @endif

    @if ($canManage && count($environments) > 0)
        @php($restoreCreate = old('_method') === null && old('objective_reference') === null)
        <x-signal.ui.card class="mt-6 p-5">
            <h2 class="text-lg font-extrabold text-ink">{{ __('Create a service objective') }}</h2>
            <p class="mt-2 text-sm leading-6 text-muted">{{ __('Set the target percentage and rolling window. Monitor reports continue to use the native SLO report and export pages.') }}</p>
            <form method="POST" action="{{ route('core.workspace.monitor.service-objectives.store', $workspace) }}" class="mt-4 grid gap-4 sm:grid-cols-2">
                @csrf
                <x-signal.ui.input-field id="new-slo-name" name="name" :label="__('Objective name')" :value="$restoreCreate ? old('name') : null" maxlength="120" required :restore="false" />
                <x-signal.ui.select-field id="new-slo-environment" name="environment_reference" :label="__('Environment')" required>
                    <option value="">{{ __('Choose an environment') }}</option>
                    @foreach ($environments as $environment)
                        <option value="{{ $environment['reference'] }}" @selected($restoreCreate && old('environment_reference') === $environment['reference'])>{{ $environment['label'] }}</option>
                    @endforeach
                </x-signal.ui.select-field>
                <x-signal.ui.select-field id="new-slo-indicator" name="indicator" :label="__('Indicator')" required>
                    <option value="availability" @selected($restoreCreate && old('indicator', 'availability') === 'availability')>{{ __('Availability') }}</option>
                    <option value="latency" @selected($restoreCreate && old('indicator') === 'latency')>{{ __('Latency') }}</option>
                </x-signal.ui.select-field>
                <x-signal.ui.input-field id="new-slo-target" name="target" :label="__('Target (%)')" type="number" min="0.001" max="99.999" step="0.001" :value="$restoreCreate ? old('target', '99.900') : '99.900'" required :restore="false" />
                <x-signal.ui.select-field id="new-slo-window" name="window_days" :label="__('Rolling window')" required>
                    <option value="7" @selected($restoreCreate && (string) old('window_days', '30') === '7')>{{ __('7 days') }}</option>
                    <option value="30" @selected(! $restoreCreate || (string) old('window_days', '30') === '30')>{{ __('30 days') }}</option>
                </x-signal.ui.select-field>
                <x-signal.ui.input-field id="new-slo-service" name="service" :label="__('Service filter (optional)')" :value="$restoreCreate ? old('service', '') : ''" maxlength="100" :restore="false" />
                <x-signal.ui.input-field id="new-slo-route" name="route" :label="__('Route filter (optional)')" :value="$restoreCreate ? old('route', '') : ''" maxlength="255" :restore="false" />
                <x-signal.ui.input-field id="new-slo-latency" name="latency_threshold_ms" :label="__('Latency threshold (ms, for latency objectives)')" type="number" min="0.001" max="600000" step="0.001" :value="$restoreCreate ? old('latency_threshold_ms', '') : ''" :restore="false" />
                <x-signal.ui.input-field id="new-slo-status-min" name="status_min" :label="__('Minimum status (for availability objectives)')" type="number" min="100" max="599" :value="$restoreCreate ? old('status_min', '200') : '200'" :restore="false" />
                <x-signal.ui.input-field id="new-slo-status-max" name="status_max" :label="__('Maximum status (for availability objectives)')" type="number" min="100" max="599" :value="$restoreCreate ? old('status_max', '399') : '399'" :restore="false" />
                <x-signal.ui.select-field id="new-slo-enabled" name="enabled" :label="__('Status')" required>
                    <option value="1" @selected(! $restoreCreate || old('enabled', '1') === '1')>{{ __('Enabled') }}</option>
                    <option value="0" @selected($restoreCreate && old('enabled') === '0')>{{ __('Disabled') }}</option>
                </x-signal.ui.select-field>
                <p class="text-xs text-muted sm:col-span-2">{{ __('Availability uses the status range. Latency uses the threshold in milliseconds. Choose the fields for the selected indicator; Monitor applies the same native validation when it saves.') }}</p>
                <x-signal.ui.button type="submit" variant="primary" class="sm:col-span-2">{{ __('Create objective') }}</x-signal.ui.button>
            </form>
        </x-signal.ui.card>
    @elseif ($canManage)
        <x-signal.ui.empty-state class="mt-6" :title="__('No mapped environments are available')" :description="__('Map an application and environment to an accessible Monitor project before creating a service objective.')" icon="pulse" />
    @endif

    <x-signal.ui.card class="mt-6 p-5">
        <form method="GET" action="{{ route('core.workspace.monitor.service-objectives', $workspace) }}" class="grid gap-3 sm:grid-cols-[1fr_auto_auto] sm:items-end">
            <x-signal.ui.input-field id="slo-search" name="slo_search" :label="__('Search objectives')" :value="request('slo_search')" maxlength="100" :restore="false" />
            <x-signal.ui.button type="submit" variant="secondary">{{ __('Search') }}</x-signal.ui.button>
            <x-signal.ui.button :href="route('core.workspace.monitor.service-objectives', $workspace)" variant="quiet">{{ __('Clear') }}</x-signal.ui.button>
        </form>
    </x-signal.ui.card>

    <div class="mt-6 grid gap-4">
        @forelse ($items as $item)
            @php($objectiveIndex = $loop->index)
            @php($restoreEdit = old('_method') === 'PATCH' && old('form_key') === $item['form_key'])
            <x-signal.ui.card class="p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-lg font-extrabold text-ink">{{ $item['name'] }}</h2>
                            <span class="ui-eyebrow">{{ $item['enabled'] ? __('Enabled') : __('Disabled') }}</span>
                        </div>
                        <p class="mt-1 text-sm text-muted">{{ $item['environment'] }} · {{ $item['indicator'] === 'latency' ? __('Latency') : __('Availability') }} · {{ $item['target'] }}% / {{ $item['window_days'] }} {{ __('days') }}</p>
                        <p class="mt-1 text-xs text-muted">{{ collect([$item['service'], $item['route']])->filter(fn ($value) => $value !== '')->implode(' · ') ?: __('All request traffic') }}</p>
                        @if ($item['indicator'] === 'latency')
                            <p class="mt-1 text-xs text-muted">{{ __('Latency threshold: :threshold ms', ['threshold' => $item['latency_threshold_ms']]) }}</p>
                        @else
                            <p class="mt-1 text-xs text-muted">{{ __('Accepted status range: :min–:max', ['min' => $item['status_min'], 'max' => $item['status_max']]) }}</p>
                        @endif
                    </div>
                    <span class="ui-eyebrow">{{ __('Desired state') }}</span>
                </div>

                @if ($item['can_mutate'])
                    <x-signal.ui.disclosure :title="__('Edit or archive this objective')" class="mt-4">
                        <form method="POST" action="{{ route('core.workspace.monitor.service-objectives.update', $workspace) }}" class="mt-4 grid gap-4 sm:grid-cols-2">
                            @csrf @method('PATCH')
                            <x-signal.ui.input type="hidden" name="objective_reference" :value="$item['reference']" :restore="false" />
                            <x-signal.ui.input type="hidden" name="form_key" :value="$item['form_key']" :restore="false" />
                            <x-signal.ui.input type="hidden" name="version" :value="$restoreEdit ? old('version', $item['version']) : $item['version']" :restore="false" />
                            <x-signal.ui.input type="hidden" name="environment_reference" :value="$item['environment_reference']" :restore="false" />
                            <div class="sm:col-span-2"><span class="ui-label">{{ __('Environment') }}</span><p class="mt-1 text-sm text-muted">{{ $item['environment'] }} <span class="text-xs">{{ __('The environment is fixed after creation.') }}</span></p></div>
                            <x-signal.ui.input-field :id="'slo-'.$objectiveIndex.'-name'" name="name" :label="__('Objective name')" :value="$restoreEdit ? old('name', $item['name']) : $item['name']" maxlength="120" required :restore="false" />
                            <x-signal.ui.select-field :id="'slo-'.$objectiveIndex.'-indicator'" name="indicator" :label="__('Indicator')" required>
                                <option value="availability" @selected(($restoreEdit ? old('indicator', $item['indicator']) : $item['indicator']) === 'availability')>{{ __('Availability') }}</option>
                                <option value="latency" @selected(($restoreEdit ? old('indicator', $item['indicator']) : $item['indicator']) === 'latency')>{{ __('Latency') }}</option>
                            </x-signal.ui.select-field>
                            <x-signal.ui.input-field :id="'slo-'.$objectiveIndex.'-target'" name="target" :label="__('Target (%)')" type="number" min="0.001" max="99.999" step="0.001" :value="$restoreEdit ? old('target', $item['target']) : $item['target']" required :restore="false" />
                            <x-signal.ui.select-field :id="'slo-'.$objectiveIndex.'-window'" name="window_days" :label="__('Rolling window')" required>
                                <option value="7" @selected((string) ($restoreEdit ? old('window_days', $item['window_days']) : $item['window_days']) === '7')>{{ __('7 days') }}</option>
                                <option value="30" @selected((string) ($restoreEdit ? old('window_days', $item['window_days']) : $item['window_days']) === '30')>{{ __('30 days') }}</option>
                            </x-signal.ui.select-field>
                            <x-signal.ui.input-field :id="'slo-'.$objectiveIndex.'-service'" name="service" :label="__('Service filter (optional)')" :value="$restoreEdit ? old('service', $item['service']) : $item['service']" maxlength="100" :restore="false" />
                            <x-signal.ui.input-field :id="'slo-'.$objectiveIndex.'-route'" name="route" :label="__('Route filter (optional)')" :value="$restoreEdit ? old('route', $item['route']) : $item['route']" maxlength="255" :restore="false" />
                            <x-signal.ui.input-field :id="'slo-'.$objectiveIndex.'-latency'" name="latency_threshold_ms" :label="__('Latency threshold (ms, for latency objectives)')" type="number" min="0.001" max="600000" step="0.001" :value="$restoreEdit ? old('latency_threshold_ms', $item['latency_threshold_ms']) : $item['latency_threshold_ms']" :restore="false" />
                            <x-signal.ui.input-field :id="'slo-'.$objectiveIndex.'-status-min'" name="status_min" :label="__('Minimum status (for availability objectives)')" type="number" min="100" max="599" :value="$restoreEdit ? old('status_min', $item['status_min']) : $item['status_min']" :restore="false" />
                            <x-signal.ui.input-field :id="'slo-'.$objectiveIndex.'-status-max'" name="status_max" :label="__('Maximum status (for availability objectives)')" type="number" min="100" max="599" :value="$restoreEdit ? old('status_max', $item['status_max']) : $item['status_max']" :restore="false" />
                            <x-signal.ui.select-field :id="'slo-'.$objectiveIndex.'-enabled'" name="enabled" :label="__('Status')" required>
                                <option value="1" @selected(($restoreEdit ? (string) old('enabled', $item['enabled'] ? '1' : '0') : ($item['enabled'] ? '1' : '0')) === '1')>{{ __('Enabled') }}</option>
                                <option value="0" @selected(($restoreEdit ? (string) old('enabled', $item['enabled'] ? '1' : '0') : ($item['enabled'] ? '1' : '0')) === '0')>{{ __('Disabled') }}</option>
                            </x-signal.ui.select-field>
                            <p class="text-xs text-muted sm:col-span-2">{{ __('Saving requires this objective version to remain current. Choose only the fields for the selected indicator.') }}</p>
                            <x-signal.ui.button type="submit" variant="primary" class="sm:col-span-2">{{ __('Save objective') }}</x-signal.ui.button>
                        </form>
                        <form method="POST" action="{{ route('core.workspace.monitor.service-objectives.archive', $workspace) }}" class="mt-4">
                            @csrf @method('DELETE')
                            <x-signal.ui.input type="hidden" name="objective_reference" :value="$item['reference']" :restore="false" />
                            <x-signal.ui.input type="hidden" name="version" :value="$item['version']" :restore="false" />
                            <x-signal.ui.checkbox :id="'slo-'.$objectiveIndex.'-confirm-archive'" name="confirm_archive" value="1" required :restore="false">{{ __('I confirm archiving this service objective.') }}</x-signal.ui.checkbox>
                            <x-signal.ui.button type="submit" variant="danger">{{ __('Archive objective') }}</x-signal.ui.button>
                        </form>
                    </x-signal.ui.disclosure>
                @elseif ($canManage)
                    <p class="mt-4 text-sm text-muted">{{ __('This saved objective version or environment mapping is unavailable, so it can be viewed but not changed. Reload the page and contact an administrator if the problem continues.') }}</p>
                @endif
            </x-signal.ui.card>
        @empty
            <x-signal.ui.empty-state :title="__('No service objectives')" :description="__('Service objectives for mapped Monitor environments appear here.')" icon="pulse" />
        @endforelse
    </div>

    @if ($items->hasPages())
        <nav class="mt-5" aria-label="{{ __('Service objective pages') }}">{{ $items->links() }}</nav>
    @endif
</x-signal.layouts.platform>
