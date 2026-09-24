@extends('analytics::layouts.app')

@section('content')
<div class="mx-auto max-w-5xl space-y-8">
    <x-signal.ui.page-header
        :eyebrow="$site->name"
        title="Conversion goals"
        description="Turn important paths and named events into measurable signals."
    >
        <x-slot:actions>
            <x-signal.ui.button :href="route('analytics.dashboard')" variant="secondary">Overview</x-signal.ui.button>
            @if ($canManage)
                <x-signal.ui.button :href="route('analytics.goals.create', $site)" variant="primary">Add goal</x-signal.ui.button>
            @endif
        </x-slot:actions>
    </x-signal.ui.page-header>

    @if (session('status'))
        <x-signal.ui.alert tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>
    @endif

    <x-signal.ui.panel as="section" class="overflow-hidden">
        @forelse ($goals as $goal)
            <div class="flex flex-col justify-between gap-4 border-b border-line p-5 last:border-b-0 sm:flex-row sm:items-center">
                <div class="min-w-0">
                    <div class="flex items-center gap-3">
                        <h2 class="truncate font-extrabold text-ink">{{ $goal->name }}</h2>
                        <x-signal.ui.badge :tone="$goal->active ? 'success' : 'neutral'">{{ $goal->active ? 'Active' : 'Paused' }}</x-signal.ui.badge>
                    </div>
                    <p class="mt-2 text-sm text-muted">{{ ucfirst($goal->kind) }} · {{ $goal->match_type }} match · <code class="rounded bg-surface-muted px-1.5 py-0.5 text-xs text-ink">{{ $goal->match_value }}</code></p>
                </div>

                @if ($canManage)
                    <div class="flex shrink-0 gap-2">
                        <x-signal.ui.button :href="route('analytics.goals.edit', [$site, $goal])" variant="secondary">Edit</x-signal.ui.button>
                        <form method="POST" action="{{ route('analytics.goals.destroy', [$site, $goal]) }}">
                            @csrf
                            @method('DELETE')
                            <x-signal.ui.button type="submit" variant="danger">Remove</x-signal.ui.button>
                        </form>
                    </div>
                @endif
            </div>
        @empty
            <x-signal.ui.empty-state
                class="m-5"
                title="Measure the action that matters."
                description="Create a path goal for a thank-you page or an event goal for an explicit action such as a demo request."
                icon="chart-bar"
            >
                @if ($canManage)
                    <x-slot:action>
                        <x-signal.ui.button :href="route('analytics.goals.create', $site)" variant="primary">Create your first goal</x-signal.ui.button>
                    </x-slot:action>
                @endif
            </x-signal.ui.empty-state>
        @endforelse
    </x-signal.ui.panel>
</div>
@endsection
