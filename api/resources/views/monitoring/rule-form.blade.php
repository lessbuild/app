@php($project = $overview->project)
@php($metric = old('metric', $rule?->metric->value ?? request('metric', 'request_error_rate')))

<x-signal.layouts.project :overview="$overview" :title="$rule ? __('Edit :rule', ['rule' => $rule->name]) : __('Add an alert rule')" :description="__('A rule watches one environment and, optionally, one service.')">
    {{-- The part a modal shows when this page is opened from a list (x-signal.overlays.page-modal). --}}
    <div data-modal-content class="grid gap-6">
        <x-signal.ui.card>
            <form method="POST" action="{{ $rule ? route('monitoring.rules.update', [$project, $rule->id]) : route('monitoring.rules.store', $project) }}" class="grid gap-5 p-4 sm:p-6">
                @csrf
                @if ($rule)
                    @method('PUT')
                    <input type="hidden" name="version" value="{{ $rule->state_version }}">
                @endif
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-signal.ui.input-field name="name" :label="__('Name')" :value="$rule?->name" maxlength="120" required />
                    <x-signal.ui.select-field name="environment_id" :label="__('Environment')" required>
                        @foreach ($overview->environments as $environment)
                            @if (! $rule || $rule->environment_id === $environment->id)
                                <option value="{{ $environment->id }}" @selected(old('environment_id', $rule?->environment_id) === $environment->id)>{{ $environment->name }}</option>
                            @endif
                        @endforeach
                    </x-signal.ui.select-field>
                </div>
                <x-signal.ui.select-field name="metric" :label="__('What to watch')" required>
                    @foreach ($metrics as $option)
                        @php($locked = ($option->isTelemetryGuardrail() && ! $plan->has('monitoring.guardrails')) || ($option->isSloBurnRate() && ! $plan->has('monitoring.slo_burn_rate')) || ($option->isAnomaly() && ! $plan->has('monitoring.anomalies')) || ($option === \App\Enums\AlertMetric::LogPatternCount && ! $plan->has('monitoring.log_patterns')))
                        <option value="{{ $option->value }}" @selected($metric === $option->value) @disabled($locked)>{{ __($option->label()) }}{{ $locked ? ' — '.__('Pro and above') : '' }}</option>
                    @endforeach
                </x-signal.ui.select-field>
                <div class="grid items-start gap-5 sm:grid-cols-2">
                    <x-signal.ui.input-field name="threshold" :label="__('Threshold')" type="number" step="0.001" :value="$rule?->thresholdValue() ?? 5" required />
                    <x-signal.ui.select-field name="window_minutes" :label="__('Over the last')" required>
                        @foreach ($windows as $minutes => $label)
                            <option value="{{ $minutes }}" @selected((int) old('window_minutes', $rule?->window_minutes ?? 5) === $minutes)>{{ __($label) }}</option>
                        @endforeach
                    </x-signal.ui.select-field>
                    <x-signal.ui.input-field name="service" :label="__('Only this service')" :value="$rule?->service" :description="__('Optional. The exact service name.')" maxlength="100" />
                    <x-signal.ui.input-field name="minimum_samples" :label="__('Fewest samples to judge')" type="number" min="1" max="1000000" :value="$rule?->minimum_samples ?? 20" required />
                </div>

                <fieldset class="grid gap-4 rounded-panel border border-line p-4">
                    <legend class="px-1 text-sm font-bold text-ink">{{ __('Only for some rule types') }}</legend>
                    <x-signal.ui.input-field name="match_text" :label="__('Text to match (log patterns)')" :value="$rule?->match_text" maxlength="120" placeholder="payment provider timeout" />
                    <x-signal.ui.select-field name="service_level_objective_id" :label="__('Objective (SLO burn rate)')">
                        <option value="">{{ __('Choose an objective') }}</option>
                        @foreach ($objectives as $objective)
                            <option value="{{ $objective->id }}" @selected((int) old('service_level_objective_id', $rule?->service_level_objective_id) === $objective->id)>{{ $objective->name }} · {{ $objective->environment->name }}</option>
                        @endforeach
                    </x-signal.ui.select-field>
                    <x-signal.ui.select-field name="metric_series_id" :label="__('Metric (numeric and anomaly rules)')">
                        <option value="">{{ __('Choose a metric') }}</option>
                        @foreach ($series as $option)
                            <option value="{{ $option->id }}" @selected((int) old('metric_series_id', $rule?->metric_series_id ?? request('series')) === $option->id)>{{ $option->name }} · {{ $option->resource_label }}</option>
                        @endforeach
                    </x-signal.ui.select-field>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <x-signal.ui.select-field name="aggregation" :label="__('Calculation')">
                            @foreach (['last' => __('Latest value'), 'mean' => __('Mean'), 'min' => __('Minimum'), 'max' => __('Maximum'), 'rate' => __('Counter rate')] as $value => $label)
                                <option value="{{ $value }}" @selected(old('aggregation', $rule?->aggregation ?? 'last') === $value)>{{ $label }}</option>
                            @endforeach
                        </x-signal.ui.select-field>
                        <x-signal.ui.select-field name="comparison" :label="__('Alert when')">
                            <option value="gte" @selected(old('comparison', $rule?->comparison ?? 'gte') === 'gte')>{{ __('At or above') }}</option>
                            <option value="lte" @selected(old('comparison', $rule?->comparison) === 'lte')>{{ __('At or below') }}</option>
                        </x-signal.ui.select-field>
                        <x-signal.ui.input-field name="freshness_seconds" :label="__('Freshest sample (seconds)')" type="number" min="30" max="3600" :value="$rule?->freshness_seconds ?? 120" />
                    </div>
                </fieldset>

                <div class="grid gap-5 sm:grid-cols-2">
                    <x-signal.ui.input-field name="trigger_checks" :label="__('Breaching checks in a row to open')" type="number" min="1" max="10" :value="$rule?->trigger_checks ?? 2" required />
                    <x-signal.ui.input-field name="recovery_checks" :label="__('Healthy checks in a row to recover')" type="number" min="1" max="10" :value="$rule?->recovery_checks ?? 2" required />
                </div>
                <x-signal.ui.checkbox name="enabled" value="1" unchecked-value="0" :checked="$rule?->enabled ?? true">{{ __('Rule is on') }}</x-signal.ui.checkbox>
                <p class="text-xs text-muted">{{ __('Rules run every minute on data that is at least a minute old. Too few samples, stale data or gaps count as “no data”, never as recovered. Changing what a rule checks closes its open incident as “rule changed”.') }}</p>
                <div class="flex flex-wrap gap-3">
                    <x-signal.ui.button type="submit" variant="primary">{{ $rule ? __('Save rule') : __('Add rule') }}</x-signal.ui.button>
                    <x-signal.ui.button :href="$rule ? route('monitoring.rules.show', [$project, $rule->id]) : route('monitoring.rules', $project)" variant="quiet">{{ __('Cancel') }}</x-signal.ui.button>
                </div>
            </form>
        </x-signal.ui.card>
    </div>
</x-signal.layouts.project>
