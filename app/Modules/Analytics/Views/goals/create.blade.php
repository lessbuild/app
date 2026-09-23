@extends('analytics::layouts.app')

@section('content')
<div class="mx-auto max-w-2xl space-y-8"><div><p class="ui-eyebrow">{{ $site->name }} · Goals</p><h2 class="mt-2 text-3xl font-extrabold tracking-tight">Add a conversion goal</h2><p class="mt-2 text-sm leading-6 text-muted">Use an exact or prefix path, or match the name passed to <code class="rounded bg-surface-muted px-1.5 py-0.5 text-xs">buildpusher.track</code>.</p></div><form class="ui-panel space-y-6 p-6 sm:p-8" method="POST" action="{{ route('analytics.goals.store', $site) }}">@csrf @include('analytics::goals.form')<div class="flex justify-end gap-3"><a class="ui-btn ui-btn-secondary" href="{{ route('analytics.goals.index', $site) }}">Cancel</a><button class="ui-btn ui-btn-primary" type="submit">Create goal</button></div></form></div>
@endsection
