@extends('analytics::layouts.app')

@section('content')
<div class="mx-auto max-w-5xl space-y-8">
    <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
        <div><p class="ui-eyebrow">{{ $site->name }}</p><h2 class="mt-2 text-3xl font-extrabold tracking-tight">Conversion goals</h2><p class="mt-2 text-sm leading-6 text-muted">Turn important paths and named events into measurable signals.</p></div>
        <div class="flex gap-2"><a class="ui-btn ui-btn-secondary" href="{{ route('analytics.dashboard') }}">Overview</a>@if ($canManage)<a class="ui-btn ui-btn-primary" href="{{ route('analytics.goals.create', $site) }}">Add goal</a>@endif</div>
    </div>
    @if (session('status'))<div class="rounded-panel border border-success/30 bg-success-soft p-4 text-sm text-success">{{ session('status') }}</div>@endif
    <section class="ui-panel overflow-hidden">
        @forelse ($goals as $goal)
            <div class="flex flex-col justify-between gap-4 border-b border-line p-5 last:border-b-0 sm:flex-row sm:items-center"><div class="min-w-0"><div class="flex items-center gap-3"><h3 class="truncate font-extrabold text-ink">{{ $goal->name }}</h3>@if ($goal->active)<span class="rounded-full bg-success-soft px-2.5 py-1 text-[0.68rem] font-extrabold text-success">Active</span>@else<span class="rounded-full bg-surface-muted px-2.5 py-1 text-[0.68rem] font-extrabold text-muted">Paused</span>@endif</div><p class="mt-2 text-sm text-muted">{{ ucfirst($goal->kind) }} · {{ $goal->match_type }} match · <code class="rounded bg-surface-muted px-1.5 py-0.5 text-xs text-ink">{{ $goal->match_value }}</code></p></div>@if ($canManage)<div class="flex shrink-0 gap-2"><a class="ui-btn ui-btn-secondary" href="{{ route('analytics.goals.edit', [$site, $goal]) }}">Edit</a><form method="POST" action="{{ route('analytics.goals.destroy', [$site, $goal]) }}">@csrf @method('DELETE')<button class="ui-btn ui-btn-ghost text-danger" type="submit">Remove</button></form></div>@endif</div>
        @empty
            <div class="p-10 text-center"><p class="ui-eyebrow">No goals yet</p><h3 class="mt-3 text-xl font-extrabold">Measure the action that matters.</h3><p class="mx-auto mt-2 max-w-md text-sm leading-6 text-muted">Create a path goal for a thank-you page or an event goal for an explicit action such as a demo request.</p>@if ($canManage)<a class="ui-btn ui-btn-primary mt-6" href="{{ route('analytics.goals.create', $site) }}">Create your first goal</a>@endif</div>
        @endforelse
    </section>
</div>
@endsection
