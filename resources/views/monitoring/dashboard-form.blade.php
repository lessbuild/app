@php($project = $overview->project)
@php($selected = collect(old('widgets', session()->hasOldInput() ? [] : ($dashboard?->widgets->pluck('type')->all() ?? array_keys(\App\Models\Dashboard::WIDGETS))))->filter(fn ($type) => is_string($type))->all())
@php($descriptions = [
    'telemetry' => __('Event volume, request duration and error rate, with a trend.'),
    'event_mix' => __('How many requests, queries, jobs, exceptions, logs and metrics arrived.'),
    'incidents' => __('Incidents from monitors and alert rules that are still open.'),
    'monitors' => __('Current health of HTTP, DNS, TLS, TCP, heartbeat and queue monitors.'),
    'objectives' => __('Compliance and error budget left for each SLO.'),
    'projects' => __('Each project and when it last sent telemetry.'),
])

<x-signal.layouts.project :overview="$overview" :title="$dashboard ? __('Edit :dashboard', ['dashboard' => $dashboard->name]) : __('Add a dashboard')" :description="__('Dashboards cover every project in the account.')">
    @error('plan')<x-signal.ui.alert tone="warning" role="alert">{{ $message }}</x-signal.ui.alert>@enderror
    <form method="POST" action="{{ $dashboard ? route('monitoring.dashboards.update', [$project, $dashboard->id]) : route('monitoring.dashboards.store', $project) }}" class="grid gap-6">
        @csrf
        @if ($dashboard) @method('PUT') @endif
        <x-signal.ui.card>
            <div class="grid items-start gap-5 p-4 sm:grid-cols-2 sm:p-6">
                <x-signal.ui.input-field name="name" :label="__('Name')" :value="$dashboard?->name" maxlength="120" placeholder="Production" required />
                <x-signal.ui.select-field name="range" :label="__('Telemetry range')" required>
                    @foreach (\App\Queries\Telemetry\TelemetrySummaryQuery::RANGES as $value => $label)
                        <option value="{{ $value }}" @selected(old('range', $dashboard?->range ?? '24h') === $value)>{{ __($label) }}</option>
                    @endforeach
                </x-signal.ui.select-field>
                <div class="sm:col-span-2"><x-signal.ui.textarea-field name="description" :label="__('Description')" :value="$dashboard?->description" maxlength="1000" rows="2" /></div>
            </div>
        </x-signal.ui.card>
        <x-signal.ui.settings-section :title="__('Widgets')" :description="__('Shown in this order. Pick at least one.')">
            <fieldset class="grid gap-3 p-4 sm:grid-cols-2 sm:p-6" @error('widgets') aria-describedby="widgets-error" @enderror>
                <legend class="sr-only">{{ __('Widgets') }}</legend>
                @foreach (\App\Models\Dashboard::WIDGETS as $type => $label)
                    <x-signal.ui.choice :id="'widget-'.$type" name="widgets[]" :value="$type" :checked="in_array($type, $selected, true)" :restore="false" :error-key="false" :label="__($label)" :description="$descriptions[$type]" card />
                @endforeach
                <div class="sm:col-span-2"><x-signal.ui.field-error name="widgets" id="widgets-error" /></div>
            </fieldset>
        </x-signal.ui.settings-section>
        <div class="flex flex-wrap gap-3">
            <x-signal.ui.button type="submit" variant="primary">{{ $dashboard ? __('Save dashboard') : __('Add dashboard') }}</x-signal.ui.button>
            <x-signal.ui.button :href="$dashboard ? route('monitoring.dashboards.show', [$project, $dashboard->id]) : route('monitoring.dashboards', $project)" variant="quiet">{{ __('Cancel') }}</x-signal.ui.button>
        </div>
    </form>

    @if ($dashboard)
        <x-signal.ui.settings-section :title="__('Delete this dashboard')" :description="__('Only the saved view is deleted. The data it shows isn’t affected.')">
            <div class="p-4 sm:p-6">
                <x-signal.ui.button variant="danger" data-modal-trigger="delete-dashboard">{{ __('Delete dashboard') }}</x-signal.ui.button>
                <x-signal.overlays.delete-confirmation id="delete-dashboard" :route="route('monitoring.dashboards.destroy', [$project, $dashboard->id])" :title="__('Delete :dashboard?', ['dashboard' => $dashboard->name])" :description="__('Only the saved view is deleted.')" :submit-label="__('Delete dashboard')" />
            </div>
        </x-signal.ui.settings-section>
    @endif
</x-signal.layouts.project>
