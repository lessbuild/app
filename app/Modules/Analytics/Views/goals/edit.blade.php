@extends('analytics::layouts.app')

@section('content')
<div class="mx-auto max-w-2xl space-y-8">
    <x-signal.ui.page-header
        :eyebrow="$site->name.' · Goals'"
        :title="'Edit '.$goal->name"
        description="Goal matching is applied to normalized paths and explicit event names."
    />

    <x-signal.ui.panel as="form" class="space-y-6 p-6 sm:p-8" method="POST" :action="route('analytics.goals.update', [$site, $goal])">
        @csrf
        @method('PUT')
        @include('analytics::goals.form', ['goal' => $goal])
        <div class="flex justify-end gap-3">
            <x-signal.ui.button :href="route('analytics.goals.index', $site)" variant="secondary">Cancel</x-signal.ui.button>
            <x-signal.ui.button type="submit" variant="primary">Save changes</x-signal.ui.button>
        </div>
    </x-signal.ui.panel>
</div>
@endsection
