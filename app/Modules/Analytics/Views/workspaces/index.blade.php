@extends('analytics::layouts.app')

@section('content')
<div class="mx-auto max-w-4xl space-y-8">
    <div><p class="ui-eyebrow">Account context</p><h2 class="mt-2 text-3xl font-extrabold tracking-tight">Your workspaces</h2><p class="mt-2 text-sm leading-6 text-muted">Keep client and product reporting separate while using one Analytics account.</p></div>
    @if (session('status'))<div class="rounded-panel border border-success/30 bg-success-soft p-4 text-sm text-success">{{ session('status') }}</div>@endif
    <div class="grid gap-4 sm:grid-cols-2">
        @foreach ($workspaces as $workspace)
            <div class="ui-panel flex flex-col justify-between gap-6 p-6"><div><p class="text-lg font-extrabold">{{ $workspace->name }}</p><p class="mt-2 text-sm text-muted">{{ $workspace->sites_count }} {{ \Illuminate\Support\Str::plural('website', $workspace->sites_count) }}</p></div><form method="POST" action="{{ route('analytics.workspaces.select', $workspace) }}">@csrf<button class="ui-btn ui-btn-secondary w-full" type="submit">Open workspace</button></form></div>
        @endforeach
    </div>
    <form class="ui-panel space-y-5 p-6" method="POST" action="{{ route('analytics.workspaces.store') }}">@csrf<h3 class="text-lg font-extrabold">Create another workspace</h3><div><label class="ui-label" for="name">Workspace name</label><input class="ui-input" id="name" name="name" value="{{ old('name') }}" placeholder="Acme marketing" required></div><button class="ui-btn ui-btn-primary" type="submit">Create workspace</button></form>
</div>
@endsection
