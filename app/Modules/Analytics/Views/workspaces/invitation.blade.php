@extends('analytics::layouts.app')

@section('content')
<div class="mx-auto max-w-xl space-y-8"><div><p class="ui-eyebrow">Workspace invitation</p><h2 class="mt-2 text-3xl font-extrabold tracking-tight">Join {{ $invitation->workspace->name }}</h2><p class="mt-2 text-sm leading-6 text-muted">You were invited as a {{ $invitation->role }}. This link expires {{ $invitation->expires_at->diffForHumans() }}.</p></div><section class="ui-panel p-6"><p class="text-sm leading-6 text-muted">Signed in as <strong class="text-ink">{{ auth()->user()->email }}</strong>. Accepting adds this account to the workspace.</p><div class="mt-6 flex gap-3"><a class="ui-btn ui-btn-secondary" href="{{ route('analytics.dashboard') }}">Cancel</a><form method="POST" action="{{ route('analytics.invitations.accept', $token) }}">@csrf<button class="ui-btn ui-btn-primary" type="submit">Accept invitation</button></form></div></section></div>
@endsection
