@extends('analytics::layouts.app')

@section('content')
<div class="mx-auto max-w-xl space-y-8">
    <x-signal.ui.page-header
        eyebrow="Analytics export"
        :title="ucfirst($export->status)"
        :description="$site->name.' · requested '.$export->created_at->diffForHumans()"
    />

    <x-signal.ui.panel as="section" class="space-y-5 p-6">
        @if (in_array($export->status, ['pending', 'processing'], true) && $export->expires_at?->isFuture())
            <meta http-equiv="refresh" content="3">
            <x-signal.ui.alert tone="info" role="status">We are preparing your CSV. This page will refresh automatically.</x-signal.ui.alert>
        @elseif ($export->status === 'completed' && $downloadAvailable)
            <x-signal.ui.alert tone="success" role="status">Your filtered report is ready. Downloads expire after {{ config('analytics.export_retention_hours') }} hours.</x-signal.ui.alert>
            <x-signal.ui.button variant="primary" :href="route('analytics.reports.exports.download-record', [$site, $export])">Download CSV</x-signal.ui.button>
        @elseif ($export->status === 'completed')
            <x-signal.ui.alert tone="info" role="status">This export completed, but its download has expired or is no longer available.</x-signal.ui.alert>
        @elseif ($export->status === 'failed')
            <x-signal.ui.alert tone="danger" role="alert">The export could not be generated. Detailed processing information is kept private.</x-signal.ui.alert>

            @if ($canRetry && $export->expires_at?->isFuture())
                <form method="POST" action="{{ route('analytics.reports.exports.retry', [$site, $export]) }}">
                    @csrf
                    <x-signal.ui.button type="submit" variant="primary">Retry export</x-signal.ui.button>
                </form>
            @endif
        @elseif (in_array($export->status, ['pending', 'processing'], true))
            <x-signal.ui.alert tone="info" role="status">This export expired before processing finished. Create a new export from the Analytics overview.</x-signal.ui.alert>
        @else
            <x-signal.ui.alert tone="info" role="status">The export status is unavailable.</x-signal.ui.alert>
        @endif

        <dl class="grid gap-3 border-t border-line pt-4 text-sm sm:grid-cols-2">
            <div>
                <dt class="text-muted">Site</dt>
                <dd class="mt-1 font-medium text-primary">{{ $site->name }}</dd>
            </div>
            <div>
                <dt class="text-muted">Requested</dt>
                <dd class="mt-1 font-medium text-primary">{{ $export->created_at->toDayDateTimeString() }}</dd>
            </div>
            <div>
                <dt class="text-muted">Download retention</dt>
                <dd class="mt-1 font-medium text-primary">{{ $export->expires_at?->toDayDateTimeString() ?? 'Unavailable' }}</dd>
            </div>
        </dl>

        <x-signal.ui.button :href="route('analytics.dashboard')" variant="secondary">Back to overview</x-signal.ui.button>
    </x-signal.ui.panel>
</div>
@endsection
