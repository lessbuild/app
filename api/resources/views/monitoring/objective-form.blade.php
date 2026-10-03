@php($project = $overview->project)
@php($indicator = old('indicator', $objective?->indicator ?? 'availability'))

<x-signal.layouts.project :overview="$overview" :title="$objective ? __('Edit :objective', ['objective' => $objective->name]) : __('Add an objective')" :description="__('Measured from request events in one environment.')">
    {{-- The part a modal shows when this page is opened from a list (x-signal.overlays.page-modal). --}}
    <div data-modal-content class="grid gap-6">
        <x-signal.ui.card>
            <form method="POST" action="{{ $objective ? route('monitoring.objectives.update', [$project, $objective->id]) : route('monitoring.objectives.store', $project) }}" class="grid gap-5 p-4 sm:grid-cols-2 sm:p-6">
                @csrf
                @if ($objective) @method('PUT') @endif
                <x-signal.ui.input-field name="name" :label="__('Name')" :value="$objective?->name" maxlength="120" required />
                <x-signal.ui.select-field name="environment_id" :label="__('Environment')" required>
                    @foreach ($overview->environments as $environment)
                        @if (! $objective || $objective->environment_id === $environment->id)
                            <option value="{{ $environment->id }}" @selected(old('environment_id', $objective?->environment_id) === $environment->id)>{{ $environment->name }}</option>
                        @endif
                    @endforeach
                </x-signal.ui.select-field>
                <x-signal.ui.select-field name="indicator" :label="__('Measure')" required>
                    <option value="availability" @selected($indicator === 'availability')>{{ __('Availability (status codes)') }}</option>
                    <option value="latency" @selected($indicator === 'latency')>{{ __('Latency (response time)') }}</option>
                </x-signal.ui.select-field>
                <x-signal.ui.input-field name="target" :label="__('Target (%)')" type="number" step="0.001" min="0.001" max="99.999" :value="$objective?->target ?? 99.9" required />
                <x-signal.ui.select-field name="window_days" :label="__('Rolling window')" required>
                    @foreach ([7, 30] as $days)
                        <option value="{{ $days }}" @selected((int) old('window_days', $objective?->window_days ?? 30) === $days)>{{ trans_choice(':count day|:count days', $days, ['count' => $days]) }}</option>
                    @endforeach
                </x-signal.ui.select-field>
                <x-signal.ui.input-field name="latency_threshold_ms" :label="__('Fast enough under (ms, latency)')" type="number" step="0.001" min="1" :value="$objective?->latency_threshold_ms ?? 300" />
                <x-signal.ui.input-field name="status_min" :label="__('Lowest good status (availability)')" type="number" min="100" max="599" :value="$objective?->status_min ?? 200" />
                <x-signal.ui.input-field name="status_max" :label="__('Highest good status (availability)')" type="number" min="100" max="599" :value="$objective?->status_max ?? 399" />
                <x-signal.ui.input-field name="service" :label="__('Only this service')" :value="$objective?->service" maxlength="100" />
                <x-signal.ui.input-field name="route" :label="__('Only this route')" :value="$objective?->route" maxlength="255" />
                <div class="sm:col-span-2"><x-signal.ui.checkbox name="enabled" value="1" unchecked-value="0" :checked="$objective?->enabled ?? true">{{ __('Objective is on') }}</x-signal.ui.checkbox></div>
                <div class="flex flex-wrap gap-3 sm:col-span-2">
                    <x-signal.ui.button type="submit" variant="primary">{{ $objective ? __('Save objective') : __('Add objective') }}</x-signal.ui.button>
                    <x-signal.ui.button :href="$objective ? route('monitoring.objectives.show', [$project, $objective->id]) : route('monitoring.objectives', $project)" variant="quiet">{{ __('Cancel') }}</x-signal.ui.button>
                </div>
            </form>
        </x-signal.ui.card>
    </div>
</x-signal.layouts.project>
