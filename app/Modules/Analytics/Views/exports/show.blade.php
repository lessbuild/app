@extends('analytics::layouts.app')

@section('content')
<div class="mx-auto max-w-xl space-y-8">
    <x-signal.ui.page-header
        eyebrow="Report export"
        :title="ucfirst($export->status)"
        :description="$export->site->name.' · requested '.$export->created_at->diffForHumans()"
    />

    <x-signal.ui.panel as="section" class="space-y-5 p-6">
        @if (in_array($export->status, ['pending', 'processing'], true))
            <meta http-equiv="refresh" content="3">
            <x-signal.ui.alert tone="info" role="status">We are preparing your CSV. This page will refresh automatically.</x-signal.ui.alert>
        @elseif ($export->status === 'completed')
            <x-signal.ui.alert tone="success" role="status">Your filtered report is ready. Downloads expire after {{ config('analytics.export_retention_hours') }} hours.</x-signal.ui.alert>
            <x-signal.ui.button variant="primary" :href="route('analytics.reports.exports.download', $token)">Download CSV</x-signal.ui.button>
        @else
            <x-signal.ui.alert tone="danger" role="alert">{{ $export->failure_message ?: 'The export could not be generated.' }}</x-signal.ui.alert>
        @endif

        <x-signal.ui.button :href="route('analytics.dashboard')" variant="secondary">Back to overview</x-signal.ui.button>
    </x-signal.ui.panel>
</div>
@endsection
