<x-signal.layouts.project :overview="$overview" :title="__('CSV export')" :description="$export->site->name">
    <x-signal.ui.card class="grid gap-4 p-5 sm:p-6">
        @if ($export->status === 'completed')
            <p class="text-sm text-muted">{{ __('Ready. The download link works until :time.', ['time' => $export->expires_at->toDayDateTimeString()]) }}</p>
            <div><x-signal.ui.button :href="route('analytics.exports.download', [$overview->project, $token])" variant="primary">{{ __('Download CSV') }}</x-signal.ui.button></div>
        @elseif ($export->status === 'failed')
            <x-signal.ui.alert tone="danger" role="alert">{{ __('The export failed. Try again from the report.') }}</x-signal.ui.alert>
        @else
            <p class="text-sm text-muted" role="status">{{ __('Your export is being prepared. Refresh this page in a moment.') }}</p>
            <div><x-signal.ui.button :href="request()->url()" variant="secondary">{{ __('Refresh') }}</x-signal.ui.button></div>
        @endif
    </x-signal.ui.card>
</x-signal.layouts.project>
