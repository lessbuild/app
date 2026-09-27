@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Deployment of :version', ['version' => $deployment->release->version])" :description="$deployment->environment->name.' · '.$deployment->deployed_at->format('Y-m-d H:i:s').' UTC · '.($deployment->actor->name ?? __('Pipeline'))">
    <x-signal.ui.card class="grid gap-2 p-5 text-sm">
        <p><span class="text-muted">{{ __('Release') }}:</span> <a class="text-primary hover:underline" href="{{ route('monitoring.releases.show', [$project, $deployment->release_id]) }}">{{ $deployment->release->version }}</a> · {{ $deployment->release->serviceLabel() }}</p>
        @if ($deployment->commit_sha)<p><span class="text-muted">{{ __('Commit') }}:</span> <code>{{ $deployment->commit_sha }}</code></p>@endif
        @if ($note)<p class="whitespace-pre-wrap">{{ $note }}</p>@endif
    </x-signal.ui.card>

    <form method="GET" action="{{ route('monitoring.deployments.show', [$project, $deployment->id]) }}" class="flex flex-wrap items-end gap-3">
        <x-signal.ui.select-field name="window" :label="__('Compare')">
            @foreach ($windowOptions as $minutes => $label)
                <option value="{{ $minutes }}" @selected((int) $filters['window'] === $minutes)>{{ __(':window before and after', ['window' => __($label)]) }}</option>
            @endforeach
        </x-signal.ui.select-field>
        <x-signal.ui.button type="submit" variant="secondary">{{ __('Compare') }}</x-signal.ui.button>
    </form>

    @if ($comparison['before'] === null)
        <x-signal.ui.empty-state icon="clock" :title="__('Too soon to compare')" :description="__('Come back once some time has passed since the deployment.')" />
    @else
        <section class="grid gap-3" aria-labelledby="before-heading">
            <h2 id="before-heading" class="text-sm font-bold text-ink">{{ __('Before') }}</h2>
            @include('telemetry._metrics', ['metrics' => $comparison['before']])
        </section>
        <section class="grid gap-3" aria-labelledby="after-heading">
            <h2 id="after-heading" class="text-sm font-bold text-ink">{{ __('After') }}</h2>
            @include('telemetry._metrics', ['metrics' => $comparison['after']])
        </section>
        <p class="text-xs text-muted">{{ __('Both sides cover :minutes minutes of this service’s telemetry in :environment. A difference is a lead, not proof of cause.', ['minutes' => intdiv($comparison['seconds'], 60), 'environment' => $deployment->environment->name]) }}</p>
    @endif
</x-signal.layouts.project>
