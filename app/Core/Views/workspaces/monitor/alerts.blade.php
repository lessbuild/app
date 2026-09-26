<x-signal.layouts.platform :title="__('Monitor alert administration')" :description="__('Manage alert rules, routing, and escalation through Monitor.')" :navigation="[]" :account-user="$user" :current-workspace="$workspace" :workspaces="$workspaces" :context-projects="$contextProjects">
    <x-signal.ui.page-header :eyebrow="$workspace->name" :title="__('Alert rules and routing')" :description="__('Monitor remains the source of alert state, plan limits, and delivery policy.')">
        <x-slot:actions>
            <x-signal.ui.button :href="route('core.workspace.monitor.destinations', $workspace)" variant="secondary">{{ __('Alert destinations') }}</x-signal.ui.button>
            <x-signal.ui.button :href="route('core.workspace.monitor.integrations', $workspace)" variant="secondary">{{ __('Integrations guide') }}</x-signal.ui.button>
            <x-signal.ui.button :href="route('core.workspace.admin', $workspace)" variant="primary">{{ __('Workspace management') }}</x-signal.ui.button>
        </x-slot:actions>
    </x-signal.ui.page-header>

    @if (session('status')) <x-signal.ui.alert tone="success" class="mt-5">{{ session('status') }}</x-signal.ui.alert> @endif
    @if ($errors->any()) <x-signal.ui.alert tone="danger" class="mt-5">{{ $errors->first() }}</x-signal.ui.alert> @endif

    <x-signal.ui.card class="mt-6 p-4">
        <form method="GET" action="{{ route('core.workspace.monitor.alerts', $workspace) }}" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <x-signal.ui.input-field id="alerts-rules-search" name="rules_search" :label="__('Find rules')" :value="request('rules_search')" maxlength="100" />
            <x-signal.ui.input-field id="alerts-environment-search" name="environment_search" :label="__('Find environments')" :value="request('environment_search')" maxlength="100" />
            <x-signal.ui.input-field id="alerts-destination-search" name="destination_search" :label="__('Find destinations')" :value="request('destination_search')" maxlength="100" />
            <x-signal.ui.input-field id="alerts-series-search" name="series_search" :label="__('Find metric series')" :value="request('series_search')" maxlength="100" />
            <x-signal.ui.input-field id="alerts-objective-search" name="objective_search" :label="__('Find objectives')" :value="request('objective_search')" maxlength="100" />
            <x-signal.ui.button type="submit" variant="secondary" class="sm:col-span-2 xl:col-span-5">{{ __('Search rules and choices') }}</x-signal.ui.button>
        </form>
        <div class="mt-3 grid gap-2 text-xs text-muted sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([['environments', __('Environment choices')], ['destinations', __('Destination choices')], ['metric_series', __('Metric series choices')], ['objectives', __('Objective choices')]] as [$key, $label])
                @if ($settings[$key]->hasPages())<nav aria-label="{{ $label }}">{{ $settings[$key]->links() }}</nav>@endif
            @endforeach
        </div>
    </x-signal.ui.card>

    @if ($settings['can_create'])
        <x-signal.ui.card class="mt-6 p-5">
            <h2 class="text-lg font-extrabold text-ink">{{ __('Create alert rule') }}</h2>
            <form method="POST" action="{{ route('core.workspace.monitor.rules.store', $workspace) }}" class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @csrf
                <x-signal.ui.input-field id="new-rule-name" name="name" :label="__('Rule name')" :value="old('name')" maxlength="120" required />
                <x-signal.ui.select-field id="new-rule-environment" name="environment_reference" :label="__('Environment')" required><option value="">{{ __('Choose environment') }}</option>@foreach ($settings['environments'] as $option)<option value="{{ $option['reference'] }}">{{ $option['label'] }}{{ $option['archived'] ? ' · '.__('archived') : '' }}</option>@endforeach</x-signal.ui.select-field>
                <x-signal.ui.select-field id="new-rule-metric" name="metric" :label="__('Metric')" required>@foreach ($settings['metrics'] as $metric)<option value="{{ $metric['value'] }}">{{ $metric['label'] }}</option>@endforeach</x-signal.ui.select-field>
                <x-signal.ui.input-field id="new-rule-threshold" name="threshold" :label="__('Threshold')" type="number" step="0.001" value="1" required />
                <x-signal.ui.select-field id="new-rule-series" name="metric_series_reference" :label="__('Metric series')"><option value="">{{ __('Choose if required') }}</option>@foreach ($settings['metric_series'] as $option)<option value="{{ $option['reference'] }}">{{ $option['label'] }}</option>@endforeach</x-signal.ui.select-field>
                <x-signal.ui.select-field id="new-rule-objective" name="objective_reference" :label="__('SLO')"><option value="">{{ __('Choose if required') }}</option>@foreach ($settings['objectives'] as $option)<option value="{{ $option['reference'] }}">{{ $option['label'] }}</option>@endforeach</x-signal.ui.select-field>
                <x-signal.ui.select-field id="new-rule-aggregation" name="aggregation" :label="__('Aggregation')">@foreach (['last' => 'Last', 'mean' => 'Mean', 'min' => 'Min', 'max' => 'Max', 'rate' => 'Rate'] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</x-signal.ui.select-field>
                <x-signal.ui.select-field id="new-rule-comparison" name="comparison" :label="__('Comparison')"><option value="gte">{{ __('At least') }}</option><option value="lte">{{ __('At most') }}</option></x-signal.ui.select-field>
                <x-signal.ui.input-field id="new-rule-freshness" name="freshness_seconds" :label="__('Freshness seconds')" type="number" value="60" min="30" max="3600" />
                <x-signal.ui.input-field id="new-rule-service" name="service" :label="__('Service (optional)')" :value="old('service')" maxlength="100" />
                <x-signal.ui.input-field id="new-rule-match" name="match_text" :label="__('Log pattern (optional)')" :value="old('match_text')" maxlength="120" />
                <x-signal.ui.select-field id="new-rule-window" name="window_minutes" :label="__('Window')"><option value="1">1 minute</option><option value="5" selected>5 minutes</option><option value="15">15 minutes</option><option value="30">30 minutes</option><option value="60">1 hour</option></x-signal.ui.select-field>
                <x-signal.ui.input-field id="new-rule-samples" name="minimum_samples" :label="__('Minimum samples')" type="number" value="1" min="1" required />
                <x-signal.ui.input-field id="new-rule-triggers" name="trigger_checks" :label="__('Trigger checks')" type="number" value="1" min="1" max="10" required />
                <x-signal.ui.input-field id="new-rule-recovery" name="recovery_checks" :label="__('Recovery checks')" type="number" value="2" min="1" max="10" required />
                <x-signal.ui.checkbox id="new-rule-enabled" name="enabled" :checked="true" :restore="false" unchecked-value="0">{{ __('Enabled') }}</x-signal.ui.checkbox>
                <x-signal.ui.button type="submit" variant="primary" class="sm:col-span-2 xl:col-span-4">{{ __('Create rule') }}</x-signal.ui.button>
            </form>
        </x-signal.ui.card>
    @endif

    <div class="mt-6 grid gap-4">
        @forelse ($items as $item)
            @php($ruleIndex = $loop->index)
            <x-signal.ui.card class="p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div><p class="ui-eyebrow">{{ $item['environment'] }} · {{ $item['metric_label'] }} · {{ $item['archived'] ? __('Archived') : ($item['enabled'] ? __('Enabled') : __('Paused')) }}</p><h2 class="mt-1 text-lg font-extrabold text-ink">{{ $item['name'] }}</h2></div>
                    <span class="text-xs text-muted">{{ __('Version :version', ['version' => $item['version']]) }}</span>
                </div>
                <p class="mt-2 text-sm text-muted">{{ __('Threshold :threshold, over :window minutes', ['threshold' => $item['conditions']['threshold'] ?? '—', 'window' => $item['conditions']['window_minutes'] ?? '—']) }}</p>
                @if ($item['can_update'] && ! $item['archived'])
                    <x-signal.ui.disclosure :title="__('Edit rule, routing, and escalation')" class="mt-4">
                        <form method="POST" action="{{ route('core.workspace.monitor.rules.update', $workspace) }}" class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            @csrf @method('PATCH')
                            <x-signal.ui.input type="hidden" name="rule_reference" :value="$item['reference']" /><x-signal.ui.input type="hidden" name="version" :value="$item['version']" />
                            <x-signal.ui.input-field :id="'rule-'.$ruleIndex.'-name'" name="name" :label="__('Rule name')" :value="$item['name']" maxlength="120" required :restore="false" />
                            <x-signal.ui.select-field :id="'rule-'.$ruleIndex.'-metric'" name="metric" :label="__('Metric')">@foreach ($settings['metrics'] as $metric)<option value="{{ $metric['value'] }}" @selected($item['metric'] === $metric['value'])>{{ $metric['label'] }}</option>@endforeach</x-signal.ui.select-field>
                            <x-signal.ui.input type="hidden" name="environment_reference" :value="$item['environment_reference']" />
                            <x-signal.ui.select-field :id="'rule-'.$ruleIndex.'-series'" name="metric_series_reference" :label="__('Metric series')"><option value="">{{ __('Choose if required') }}</option>@foreach ($settings['metric_series'] as $option)<option value="{{ $option['reference'] }}" @selected($item['metric_series_reference'] === $option['reference'])>{{ $option['label'] }}</option>@endforeach</x-signal.ui.select-field>
                            <x-signal.ui.select-field :id="'rule-'.$ruleIndex.'-objective'" name="objective_reference" :label="__('SLO')"><option value="">{{ __('Choose if required') }}</option>@foreach ($settings['objectives'] as $option)<option value="{{ $option['reference'] }}" @selected($item['objective_reference'] === $option['reference'])>{{ $option['label'] }}</option>@endforeach</x-signal.ui.select-field>
                            <x-signal.ui.input-field :id="'rule-'.$ruleIndex.'-threshold'" name="threshold" :label="__('Threshold')" type="number" step="0.001" :value="$item['conditions']['threshold'] ?? 1" required :restore="false" />
                            <x-signal.ui.select-field :id="'rule-'.$ruleIndex.'-window'" name="window_minutes" :label="__('Window')">@foreach ([1, 5, 15, 30, 60] as $window)<option value="{{ $window }}" @selected(($item['conditions']['window_minutes'] ?? 5) === $window)>{{ $window }}</option>@endforeach</x-signal.ui.select-field>
                            <x-signal.ui.input-field :id="'rule-'.$ruleIndex.'-samples'" name="minimum_samples" :label="__('Minimum samples')" type="number" :value="$item['conditions']['minimum_samples'] ?? 1" required :restore="false" />
                            <x-signal.ui.input-field :id="'rule-'.$ruleIndex.'-triggers'" name="trigger_checks" :label="__('Trigger checks')" type="number" :value="$item['conditions']['trigger_checks'] ?? 1" required :restore="false" />
                            <x-signal.ui.input-field :id="'rule-'.$ruleIndex.'-recovery'" name="recovery_checks" :label="__('Recovery checks')" type="number" :value="$item['conditions']['recovery_checks'] ?? 1" required :restore="false" />
                            <x-signal.ui.input-field :id="'rule-'.$ruleIndex.'-service'" name="service" :label="__('Service')" :value="$item['conditions']['service'] ?? ''" maxlength="100" :restore="false" />
                            <x-signal.ui.input-field :id="'rule-'.$ruleIndex.'-match'" name="match_text" :label="__('Log pattern')" :value="$item['conditions']['match_text'] ?? ''" maxlength="120" :restore="false" />
                            <x-signal.ui.select-field :id="'rule-'.$ruleIndex.'-aggregation'" name="aggregation" :label="__('Aggregation')">@foreach (['last' => 'Last', 'mean' => 'Mean', 'min' => 'Min', 'max' => 'Max', 'rate' => 'Rate'] as $value => $label)<option value="{{ $value }}" @selected(($item['conditions']['aggregation'] ?? 'last') === $value)>{{ $label }}</option>@endforeach</x-signal.ui.select-field>
                            <x-signal.ui.select-field :id="'rule-'.$ruleIndex.'-comparison'" name="comparison" :label="__('Comparison')"><option value="gte" @selected(($item['conditions']['comparison'] ?? 'gte') === 'gte')>{{ __('At least') }}</option><option value="lte" @selected(($item['conditions']['comparison'] ?? 'gte') === 'lte')>{{ __('At most') }}</option></x-signal.ui.select-field>
                            <x-signal.ui.input-field :id="'rule-'.$ruleIndex.'-freshness'" name="freshness_seconds" :label="__('Freshness seconds')" type="number" :value="$item['conditions']['freshness_seconds'] ?? 60" :restore="false" />
                            <x-signal.ui.checkbox :id="'rule-'.$ruleIndex.'-enabled'" name="enabled" :checked="$item['enabled']" :restore="false" unchecked-value="0">{{ __('Enabled') }}</x-signal.ui.checkbox>
                            <x-signal.ui.button type="submit" variant="primary">{{ __('Save rule') }}</x-signal.ui.button>
                        </form>

                        <form method="POST" action="{{ route('core.workspace.monitor.routing.update', $workspace) }}" class="mt-5 grid gap-3 border-t border-line pt-4 sm:grid-cols-2">
                            @csrf @method('PUT')<x-signal.ui.input type="hidden" name="rule_reference" :value="$item['reference']" /><x-signal.ui.input type="hidden" name="version" :value="$item['version']" />
                            <p class="font-semibold sm:col-span-2">{{ __('Primary destinations') }}</p>
                            @foreach ($settings['destinations'] as $destination)<x-signal.ui.checkbox :id="'rule-'.$ruleIndex.'-destination-'.$loop->index" name="destinations[]" :checked="in_array($destination['reference'], $item['destinations'], true)" :restore="false" :value="$destination['reference']">{{ $destination['name'] }}{{ $destination['archived'] ? ' · '.__('archived') : ($destination['enabled'] ? '' : ' · '.__('disabled')) }}</x-signal.ui.checkbox>@endforeach
                            <x-signal.ui.checkbox :id="'rule-'.$ruleIndex.'-opened'" name="opened" :checked="$item['opened']" :restore="false" unchecked-value="0">{{ __('Incident opened') }}</x-signal.ui.checkbox>
                            <x-signal.ui.checkbox :id="'rule-'.$ruleIndex.'-recovered'" name="recovered" :checked="$item['recovered']" :restore="false" unchecked-value="0">{{ __('Incident recovered') }}</x-signal.ui.checkbox>
                            <x-signal.ui.button type="submit" variant="secondary" class="sm:col-span-2">{{ __('Save routing') }}</x-signal.ui.button>
                        </form>

                        <form method="POST" action="{{ route('core.workspace.monitor.escalations.update', $workspace) }}" class="mt-5 grid gap-3 border-t border-line pt-4 sm:grid-cols-2">
                            @csrf @method('PUT')<x-signal.ui.input type="hidden" name="rule_reference" :value="$item['reference']" /><x-signal.ui.input type="hidden" name="version" :value="$item['version']" />
                            <p class="font-semibold sm:col-span-2">{{ __('Escalations (up to :count steps)', ['count' => $settings['escalation_step_limit']]) }}</p>
                            @for ($position = 0; $position < 10; $position++) @php($step = $item['escalations'][$position] ?? ['destination_reference' => null, 'delay_minutes' => 5 * ($position + 1)])<x-signal.ui.select-field :id="'rule-'.$ruleIndex.'-escalation-'.$position" :name="'escalations['.$position.'][destination_reference]'" :label="__('Step :step destination', ['step' => $position + 1])"><option value="">{{ __('No escalation step') }}</option>@foreach ($settings['destinations'] as $destination)<option value="{{ $destination['reference'] }}" @selected($step['destination_reference'] === $destination['reference'])>{{ $destination['name'] }}{{ $destination['archived'] ? ' · '.__('archived') : '' }}</option>@endforeach</x-signal.ui.select-field><x-signal.ui.input-field :id="'rule-'.$ruleIndex.'-delay-'.$position" name="escalations[{{ $position }}][delay_minutes]" :label="__('Delay minutes')" type="number" min="1" max="10080" :value="$step['delay_minutes']" :restore="false" />@endfor
                            <x-signal.ui.button type="submit" variant="secondary" class="sm:col-span-2">{{ __('Save escalations') }}</x-signal.ui.button>
                        </form>
                        <form method="POST" action="{{ route('core.workspace.monitor.rules.archive', $workspace) }}" class="mt-4">@csrf @method('DELETE')<x-signal.ui.input type="hidden" name="rule_reference" :value="$item['reference']" /><x-signal.ui.input type="hidden" name="version" :value="$item['version']" /><x-signal.ui.button type="submit" variant="danger">{{ __('Archive rule') }}</x-signal.ui.button></form>
                    </x-signal.ui.disclosure>
                @endif
            </x-signal.ui.card>
        @empty
            <x-signal.ui.empty-state :title="__('No alert rules')" :description="__('Create a rule to detect service and telemetry conditions.')" icon="bell" />
        @endforelse
    </div>
    @if ($items->hasPages())<nav class="mt-5" aria-label="{{ __('Alert rule pages') }}">{{ $items->links() }}</nav>@endif
</x-signal.layouts.platform>
