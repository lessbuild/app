@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Findings')" :description="__('Everything Security has found, most serious first. Findings resolve themselves when a scan no longer sees them.')">
    <x-signal.ui.card class="p-4 sm:p-5">
        <form method="GET" class="grid gap-3 sm:grid-cols-4 sm:items-end">
            <x-signal.ui.select-field name="status" :label="__('Status')" :show-errors="false">
                @foreach (['open' => __('Open'), 'ignored' => __('Ignored'), 'resolved' => __('Resolved')] as $value => $label)
                    <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                @endforeach
            </x-signal.ui.select-field>
            <x-signal.ui.select-field name="source" :label="__('Check')" :show-errors="false">
                <option value="">{{ __('All checks') }}</option>
                @foreach (\App\Models\SecurityFinding::SOURCES as $value => $label)
                    <option value="{{ $value }}" @selected($filters['source'] === $value)>{{ __($label) }}</option>
                @endforeach
            </x-signal.ui.select-field>
            <x-signal.ui.select-field name="severity" :label="__('Severity')" :show-errors="false">
                <option value="">{{ __('Any severity') }}</option>
                @foreach (\App\Models\SecurityFinding::SEVERITIES as $value => $severity)
                    <option value="{{ $value }}" @selected($filters['severity'] === $value)>{{ __($severity['label']) }}</option>
                @endforeach
            </x-signal.ui.select-field>
            <div><x-signal.ui.button type="submit" variant="primary">{{ __('Show') }}</x-signal.ui.button></div>
        </form>
    </x-signal.ui.card>

    @if ($findings->isEmpty())
        <x-signal.ui.empty-state icon="shield-check" :title="__('No findings here')" :description="$filters['status'] === 'open' ? __('Nothing open matches. Checks keep running in the background.') : __('Nothing matches these filters.')" />
    @else
        <x-signal.ui.card class="px-5 sm:px-6">
            <ul class="divide-y divide-line">
                @foreach ($findings as $finding)
                    @include('security._finding')
                @endforeach
            </ul>
        </x-signal.ui.card>
        {{ $findings->links() }}
    @endif
</x-signal.layouts.project>
