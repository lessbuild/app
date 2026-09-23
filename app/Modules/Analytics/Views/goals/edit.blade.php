@extends('analytics::layouts.app')

@section('content')
<div class="mx-auto max-w-2xl space-y-8"><div><p class="ui-eyebrow">{{ $site->name }} · Goals</p><h2 class="mt-2 text-3xl font-extrabold tracking-tight">Edit {{ $goal->name }}</h2><p class="mt-2 text-sm leading-6 text-muted">Goal matching is applied to normalized paths and explicit event names.</p></div><form class="ui-panel space-y-6 p-6 sm:p-8" method="POST" action="{{ route('analytics.goals.update', [$site, $goal]) }}">@csrf @method('PUT') @include('analytics::goals.form', ['goal' => $goal])<div class="flex justify-end gap-3"><a class="ui-btn ui-btn-secondary" href="{{ route('analytics.goals.index', $site) }}">Cancel</a><button class="ui-btn ui-btn-primary" type="submit">Save changes</button></div></form></div>
@endsection
