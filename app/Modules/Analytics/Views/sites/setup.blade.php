@extends('analytics::layouts.app')

@section('content')
@php($trackingSnippet = sprintf('<script defer src="%s" data-site="%s"></script>', url('/tracker/v1.js'), $site->public_id))
<div class="mx-auto max-w-4xl space-y-8">
    <x-signal.ui.page-header
        :eyebrow="$site->name"
        title="Connect your website"
        description="Verify the domain, then install the tracker in your site’s shared layout."
    >
        <x-slot:actions>
            <x-signal.ui.button :href="route('analytics.dashboard')" variant="secondary">Back to overview</x-signal.ui.button>
        </x-slot:actions>
    </x-signal.ui.page-header>

    @if (session('status'))
        <x-signal.ui.alert tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>
    @endif

    <div class="grid gap-5 lg:grid-cols-2">
        <x-signal.ui.panel as="section" class="p-6">
            <div class="flex items-start gap-4"><span class="grid size-9 shrink-0 place-items-center rounded-full bg-primary text-sm font-extrabold text-on-primary">1</span><div>
                <h3 class="font-extrabold">Verify your domain</h3>
                <p class="mt-2 text-sm leading-6 text-muted">Add a DNS TXT record named <code class="rounded bg-surface-muted px-1.5 py-1 text-xs">{{ $site->verificationRecordName() }}</code> with this value, then verify ownership. Local development accepts the token directly.</p>
                <x-signal.ui.card tone="muted" class="mt-4 break-all p-3 font-mono text-xs text-ink">{{ $site->verification_token }}</x-signal.ui.card>
                <form class="mt-5 space-y-3" method="POST" action="{{ route('analytics.sites.verify', $site) }}">
                    @csrf
                    <x-signal.ui.input-field name="token" label="Verification token" placeholder="Paste the token to verify" required />
                    <x-signal.ui.button class="w-full" type="submit" variant="primary">Verify domain</x-signal.ui.button>
                </form>
            </div></div>
        </x-signal.ui.panel>
        <x-signal.ui.panel as="section" class="p-6">
            <div class="flex items-start gap-4"><span class="grid size-9 shrink-0 place-items-center rounded-full bg-primary text-sm font-extrabold text-on-primary">2</span><div class="min-w-0">
                <h3 class="font-extrabold">Install the tracker</h3>
                <p class="mt-2 text-sm leading-6 text-muted">Place this before the closing <code class="rounded bg-surface-muted px-1.5 py-1 text-xs">&lt;/head&gt;</code> tag on {{ $site->domains[0] }}.</p>
                <x-signal.ui.code-block :code="$trackingSnippet" class="mt-4" />
                <p class="mt-4 text-xs leading-5 text-muted">The script is asynchronous, sends only normalized pageview metadata, and will not block your site if collection is unavailable.</p>
            </div></div>
        </x-signal.ui.panel>
    </div>
    <x-signal.ui.panel as="section" class="p-6">
        <p class="ui-eyebrow">First-event diagnostic</p>
        <h2 class="mt-2 text-lg font-extrabold">{{ $site->events()->exists() ? 'Your first event has arrived.' : 'Waiting for the first event.' }}</h2>
        <p class="mt-2 text-sm leading-6 text-muted">{{ $site->events()->exists() ? 'The tracker is connected and your reports can begin processing traffic.' : 'After verification, load the website once with the tracker installed, then refresh this page.' }}</p>
        <div class="mt-4 flex flex-wrap items-center gap-3">
            @if ($site->isVerified())
                <x-signal.ui.badge tone="success">Verified</x-signal.ui.badge>
            @else
                <x-signal.ui.badge tone="warning">Waiting for verification</x-signal.ui.badge>
            @endif
            <span class="text-sm text-muted">{{ $site->last_event_at ? 'Last event '.$site->last_event_at->diffForHumans() : 'No events received yet' }}</span>
        </div>
    </x-signal.ui.panel>
</div>
@endsection
