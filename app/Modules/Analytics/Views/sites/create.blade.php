@extends('analytics::layouts.app')

@section('content')
<div class="mx-auto max-w-2xl space-y-8">
    <div>
        <p class="ui-eyebrow">Website setup</p>
        <h2 class="mt-2 text-3xl font-extrabold tracking-tight">Add a website</h2>
        <p class="mt-2 text-sm leading-6 text-muted">Start with the domain where your public site runs. You can add more domains after the first verification.</p>
    </div>
    <form class="ui-panel space-y-6 p-6 sm:p-8" method="POST" action="{{ route('analytics.sites.store') }}">
        @csrf
        @if ($errors->any())
            <div class="rounded-card border border-danger/30 bg-danger-soft p-4 text-sm text-danger">
                <ul class="list-disc pl-5">
                    @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        @endif
        <div><label class="ui-label" for="name">Website name</label><input class="ui-input" id="name" name="name" value="{{ old('name') }}" placeholder="Marketing site" required></div>
        <div><label class="ui-label" for="domain">Primary domain</label><input class="ui-input" id="domain" name="domain" value="{{ old('domain') }}" placeholder="example.com" required><p class="mt-2 text-xs text-muted">Use the hostname only. We’ll accept a URL too and normalize it.</p></div>
        <div><label class="ui-label" for="timezone">Reporting timezone</label><select class="ui-input" id="timezone" name="timezone" required><option value="UTC">UTC</option><option value="America/New_York">America/New_York</option><option value="America/Los_Angeles">America/Los_Angeles</option><option value="Europe/London">Europe/London</option><option value="Europe/Berlin">Europe/Berlin</option><option value="Asia/Singapore">Asia/Singapore</option></select><p class="mt-2 text-xs text-muted">Daily reports use this timezone. Choose carefully before collection begins.</p></div>
        <div class="flex justify-end gap-3"><a class="ui-btn ui-btn-secondary" href="{{ route('analytics.dashboard') }}">Cancel</a><button class="ui-btn ui-btn-primary" type="submit">Create website</button></div>
    </form>
</div>
@endsection
