@extends('analytics::layouts.app')

@section('content')
<div class="mx-auto max-w-xl space-y-8">
    <x-signal.ui.page-header
        eyebrow="Workspace invitation"
        :title="'Join '.$invitation->workspace->name"
        :description="'You were invited as a '.$invitation->role.'. This link expires '.$invitation->expires_at->diffForHumans().'.'"
    />

    <x-signal.ui.panel as="section" class="p-6">
        <p class="text-sm leading-6 text-muted">Signed in as <strong class="text-ink">{{ auth()->user()->email }}</strong>. Accepting adds this account to the workspace.</p>
        <div class="mt-6 flex gap-3">
            <x-signal.ui.button :href="route('analytics.dashboard')" variant="secondary">Cancel</x-signal.ui.button>
            <form method="POST" action="{{ route('analytics.invitations.accept', $token) }}">
                @csrf
                <x-signal.ui.button type="submit" variant="primary">Accept invitation</x-signal.ui.button>
            </form>
        </div>
    </x-signal.ui.panel>
</div>
@endsection
