@extends('analytics::layouts.app')

@section('content')
<div class="mx-auto max-w-4xl space-y-8">
    <x-signal.ui.page-header
        eyebrow="Account context"
        title="Your workspaces"
        description="Keep client and product reporting separate while using one Analytics account."
    />

    @if (session('status'))
        <x-signal.ui.alert tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>
    @endif

    <div class="grid gap-4 sm:grid-cols-2">
        @foreach ($workspaces as $workspace)
            <x-signal.ui.panel as="article" class="flex flex-col justify-between gap-6 p-6">
                <div>
                    <h2 class="text-lg font-extrabold">{{ $workspace->name }}</h2>
                    <p class="mt-2 text-sm text-muted">{{ $workspace->sites_count }} {{ \Illuminate\Support\Str::plural('website', $workspace->sites_count) }}</p>
                </div>
                <form method="POST" action="{{ route('analytics.workspaces.select', $workspace) }}">
                    @csrf
                    <x-signal.ui.button class="w-full" type="submit" variant="secondary">Open workspace</x-signal.ui.button>
                </form>
            </x-signal.ui.panel>
        @endforeach
    </div>

    <x-signal.ui.panel as="form" class="space-y-5 p-6" method="POST" :action="route('analytics.workspaces.store')">
        @csrf
        <h2 class="text-lg font-extrabold">Create another workspace</h2>
        <x-signal.ui.input-field name="name" label="Workspace name" :value="old('name')" placeholder="Acme marketing" required />
        <x-signal.ui.button type="submit" variant="primary">Create workspace</x-signal.ui.button>
    </x-signal.ui.panel>
</div>
@endsection
