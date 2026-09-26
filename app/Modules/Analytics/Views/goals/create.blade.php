@extends('analytics::layouts.app')

@section('content')
<div class="mx-auto max-w-2xl space-y-8">
    <x-signal.ui.page-header
        :eyebrow="$site->name.' · Goals'"
        title="Add a conversion goal"
        description="Use an exact or prefix path, or match the name passed to buildpusher.track."
    />

    <x-signal.ui.panel as="form" class="space-y-6 p-6 sm:p-8" method="POST" :action="route('analytics.goals.store', $site)">
        @csrf
        @include('analytics::goals.form')
        <div class="flex justify-end gap-3">
            <x-signal.ui.button :href="route('analytics.goals.index', $site)" variant="secondary">Cancel</x-signal.ui.button>
            <x-signal.ui.button type="submit" variant="primary">Create goal</x-signal.ui.button>
        </div>
    </x-signal.ui.panel>
</div>
@endsection
