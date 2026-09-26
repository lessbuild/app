@extends('monitor::layouts.app')
@section('title', 'Record deployment')
@section('breadcrumb', 'Record deployment')
@section('content')
<div class="mx-auto max-w-3xl space-y-6">
    <a href="{{ route('monitor.deployments.index', [$application, $environment]) }}" class="text-xs font-bold text-primary hover:underline dark:text-primary">← Deployment history</a>
    <x-monitor::ui.page-header title="Record deployment" :description="$application->name.' / '.$environment->name.' · Record what has already shipped. This does not deploy code.'" />
    <x-signal.ui.panel as="form" method="POST" action="{{ route('monitor.deployments.store', [$application, $environment]) }}" class="space-y-5 p-6">
        @csrf
        <x-monitor::ui.input name="version" label="Version" maxlength="128" placeholder="build-42 or a commit SHA" required />
        <div class="grid gap-5 sm:grid-cols-2"><x-monitor::ui.input name="service" label="Service (optional)" maxlength="100" placeholder="api" /><x-monitor::ui.input name="service_namespace" label="Service namespace (optional)" maxlength="100" placeholder="shop" /></div>
        <p class="text-xs leading-5 text-muted dark:text-subtle">Match the labels reported by your telemetry. Missing service names represent a separate, unspecified service.</p>
        <x-monitor::ui.input name="commit_sha" label="Commit ID (optional)" maxlength="64" minlength="7" />
        <x-monitor::ui.input name="deployed_at" label="Completed at (optional, ISO 8601 with timezone)" placeholder="2026-09-21T12:00:00Z" />
        <p class="text-xs text-muted dark:text-subtle">Leave the time blank to use server time. Record a rollback as a new deployment of the older version.</p>
        <x-monitor::ui.input name="note" label="Note (optional, no secrets)" maxlength="1000" />
        <x-monitor::ui.input name="deployment_id" label="Deployment UUID (reuse unchanged for retries)" :value="$deploymentId" required readonly />
        <x-monitor::ui.button>Record completed deployment</x-monitor::ui.button>
    </x-signal.ui.panel>
</div>
@endsection
