@extends('monitor::layouts.app')
@section('title', $application->exists ? 'Edit application' : 'New application')
@section('breadcrumb', 'Applications')
@section('content')
<div class="mx-auto max-w-3xl space-y-6">
    <a href="{{ $application->exists ? route('monitor.applications.show', $application) : route('monitor.applications.index') }}" class="text-xs font-bold text-primary hover:underline dark:text-primary">← Back to applications</a>
    <x-monitor::ui.page-header :title="$application->exists ? 'Application settings' : 'Meet your next application.'" :description="$application->exists ? 'Update the details your team sees in '.config('app.name').'.' : 'Start with a production environment and a private ingestion token. Add other environments whenever you need them.'" />
    <form method="POST" action="{{ $application->exists ? route('monitor.applications.update', $application) : route('monitor.applications.store') }}" class="ui-panel space-y-6 p-6 sm:p-8">
        @csrf
        @if($application->exists) @method('PATCH') @endif
        <x-monitor::ui.input name="name" label="Application name" :value="$application->name" placeholder="Payments API" maxlength="120" required autofocus />
        <div class="grid gap-5 sm:grid-cols-2">
            <x-monitor::ui.input name="framework" label="Language or framework" :value="$application->framework" list="framework-options" maxlength="80" required />
            <datalist id="framework-options">@foreach(\App\Modules\Monitor\Models\Application::FRAMEWORK_PRESETS as $framework)<option value="{{ $framework }}">@endforeach</datalist>
            <x-monitor::ui.input name="framework_version" label="Version (optional)" :value="$application->framework_version" placeholder="e.g. 13.x" maxlength="40" />
        </div>
        <fieldset @if($errors->has('accent')) aria-describedby="accent-error" @endif>
            <legend class="ui-label">Application accent</legend>
            <div class="flex flex-wrap gap-3">
                @foreach(\App\Modules\Monitor\Models\Application::ACCENTS as $accent)
                    <x-monitor::ui.choice :id="'accent-'.$accent" name="accent" type="radio" :value="$accent" :checked="old('accent', $application->accent) === $accent" :label="ucfirst($accent)" :error-key="false" card />
                @endforeach
            </div>
            @error('accent')<p id="accent-error" class="ui-error">{{ $message }}</p>@enderror
        </fieldset>
        <div class="flex items-center justify-between gap-4 border-t border-line pt-5 dark:border-line"><p class="text-xs text-muted dark:text-subtle">Works with the JSON API or compatible OpenTelemetry exporters.</p><x-monitor::ui.button>{{ $application->exists ? 'Save changes' : 'Create application' }}</x-monitor::ui.button></div>
    </form>
</div>
@endsection
