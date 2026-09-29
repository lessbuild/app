@php($project = $overview->project)
<x-signal.layouts.project :overview="$overview" :title="$dashboard ? __('Edit :dashboard', ['dashboard' => $dashboard->name]) : __('Add a dashboard')" :description="__('Dashboards cover every project in the account.')">
    <x-signal.ui.plan-limit-alert service="monitoring" />
    <form method="POST" action="{{ $dashboard ? route('monitoring.dashboards.update', [$project, $dashboard->id]) : route('monitoring.dashboards.store', $project) }}" class="grid gap-6">
        @csrf
        @if ($dashboard) @method('PUT') @endif
        <x-signal.ui.card class="grid gap-6 p-4 sm:p-6">
            @include('monitoring._dashboard-fields')
        </x-signal.ui.card>
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
