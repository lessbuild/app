@extends('analytics::layouts.app')

@section('content')
<div class="mx-auto max-w-4xl space-y-8">
    <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
        <div><p class="ui-eyebrow">{{ $site->name }}</p><h2 class="mt-2 text-3xl font-extrabold tracking-tight">Connect your website</h2><p class="mt-2 text-sm leading-6 text-muted">Verify the domain, then install the tracker in your site’s shared layout.</p></div>
        <a class="ui-btn ui-btn-secondary" href="{{ route('analytics.dashboard') }}">Back to overview</a>
    </div>
    @if (session('status'))<div class="rounded-panel border border-success/30 bg-success-soft p-4 text-sm text-success">{{ session('status') }}</div>@endif
    <div class="grid gap-5 lg:grid-cols-2">
        <section class="ui-panel p-6">
            <div class="flex items-start gap-4"><span class="grid size-9 shrink-0 place-items-center rounded-full bg-primary text-sm font-extrabold text-on-primary">1</span><div>
                <h3 class="font-extrabold">Verify your domain</h3>
                <p class="mt-2 text-sm leading-6 text-muted">Add a DNS TXT record named <code class="rounded bg-surface-muted px-1.5 py-1 text-xs">{{ $site->verificationRecordName() }}</code> with this value, then verify ownership. Local development accepts the token directly.</p>
                <div class="mt-4 break-all rounded-card border border-line bg-surface-muted p-3 font-mono text-xs text-ink">{{ $site->verification_token }}</div>
                <form class="mt-5 space-y-3" method="POST" action="{{ route('analytics.sites.verify', $site) }}">
                    @csrf
                    <label class="ui-label" for="token">Verification token</label>
                    <input class="ui-input" id="token" name="token" placeholder="Paste the token to verify" required>
                    @error('token')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                    <button class="ui-btn ui-btn-primary w-full" type="submit">Verify domain</button>
                </form>
            </div></div>
        </section>
        <section class="ui-panel p-6">
            <div class="flex items-start gap-4"><span class="grid size-9 shrink-0 place-items-center rounded-full bg-primary text-sm font-extrabold text-on-primary">2</span><div class="min-w-0">
                <h3 class="font-extrabold">Install the tracker</h3>
                <p class="mt-2 text-sm leading-6 text-muted">Place this before the closing <code class="rounded bg-surface-muted px-1.5 py-1 text-xs">&lt;/head&gt;</code> tag on {{ $site->domains[0] }}.</p>
                <pre class="mt-4 overflow-x-auto rounded-card bg-zinc-950 p-4 text-xs leading-6 text-zinc-200"><code>&lt;script defer src="{{ url('/tracker/v1.js') }}" data-site="{{ $site->public_id }}"&gt;&lt;/script&gt;</code></pre>
                <p class="mt-4 text-xs leading-5 text-muted">The script is asynchronous, sends only normalized pageview metadata, and will not block your site if collection is unavailable.</p>
            </div></div>
        </section>
    </div>
    <section class="ui-panel p-6"><p class="ui-eyebrow">First-event diagnostic</p><h3 class="mt-2 text-lg font-extrabold">{{ $site->events()->exists() ? 'Your first event has arrived.' : 'Waiting for the first event.' }}</h3><p class="mt-2 text-sm leading-6 text-muted">{{ $site->events()->exists() ? 'The tracker is connected and your reports can begin processing traffic.' : 'After verification, load the website once with the tracker installed, then refresh this page.' }}</p><div class="mt-4 flex flex-wrap items-center gap-3">
        @if ($site->isVerified())<span class="rounded-full bg-success-soft px-3 py-1 text-xs font-extrabold text-success">Verified</span>@else<span class="rounded-full bg-warning-soft px-3 py-1 text-xs font-extrabold text-warning">Waiting for verification</span>@endif
        <span class="text-sm text-muted">{{ $site->last_event_at ? 'Last event '.$site->last_event_at->diffForHumans() : 'No events received yet' }}</span>
    </div></section>
</div>
@endsection
