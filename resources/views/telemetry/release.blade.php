@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="$release->version" :description="$release->serviceLabel()">
    <form method="GET" action="{{ route('monitoring.releases.show', [$project, $release->id]) }}" class="flex flex-wrap items-end gap-3">
        <x-signal.ui.select-field name="range" :label="__('Time')">
            @foreach ($rangeOptions as $value => $label)
                <option value="{{ $value }}" @selected($filters['range'] === $value)>{{ __($label) }}</option>
            @endforeach
        </x-signal.ui.select-field>
        <x-signal.ui.select-field name="environment" :label="__('Environment')">
            <option value="">{{ __('All') }}</option>
            @foreach ($overview->environments as $environment)
                <option value="{{ $environment->id }}" @selected(($filters['environment'] ?? null) === $environment->id)>{{ $environment->name }}</option>
            @endforeach
        </x-signal.ui.select-field>
        <x-signal.ui.button type="submit" variant="secondary">{{ __('Show') }}</x-signal.ui.button>
    </form>

    @include('telemetry._metrics', ['metrics' => $metrics])

    <x-signal.ui.table :caption="__('Issues in this release')">
        <x-slot:head><tr><th scope="col">{{ __('Issue') }}</th><th scope="col">{{ __('Status') }}</th></tr></x-slot:head>
        @forelse ($issues as $issue)
            <tr>
                <td><a class="text-primary hover:underline" href="{{ route('monitoring.issues.show', [$project, $issue->id]) }}">{{ $issue->title }}</a></td>
                <td><x-signal.ui.badge :tone="$issue->status->tone()">{{ $issue->status->label() }}</x-signal.ui.badge></td>
            </tr>
        @empty
            <tr><td colspan="2" class="py-8 text-center text-muted">{{ __('No exceptions from this release in this range.') }}</td></tr>
        @endforelse
    </x-signal.ui.table>

    <x-signal.ui.table :caption="__('Deployments')">
        <x-slot:head><tr><th scope="col">{{ __('When (UTC)') }}</th><th scope="col">{{ __('Environment') }}</th><th scope="col">{{ __('By') }}</th></tr></x-slot:head>
        @forelse ($deployments as $deployment)
            <tr>
                <td class="whitespace-nowrap"><a class="text-primary hover:underline" href="{{ route('monitoring.deployments.show', [$project, $deployment->id]) }}">{{ $deployment->deployed_at->format('Y-m-d H:i') }}</a></td>
                <td>{{ $deployment->environment->name }}</td>
                <td>{{ $deployment->actor->name ?? __('Pipeline') }}</td>
            </tr>
        @empty
            <tr><td colspan="3" class="py-8 text-center text-muted">{{ __('No deployments recorded for this release.') }}</td></tr>
        @endforelse
    </x-signal.ui.table>
</x-signal.layouts.project>
