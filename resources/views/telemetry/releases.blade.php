@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Releases')" :description="__('Versions your apps report (service.version), and the deployments that shipped them.')">
    <form method="GET" action="{{ route('monitoring.releases', $project) }}" class="flex flex-wrap items-end gap-3" role="search">
        <x-signal.ui.input-field name="q" :label="__('Search')" :value="$filters['q'] ?? null" :restore="false" field-class="min-w-56 flex-1" />
        <x-signal.ui.button type="submit" variant="secondary">{{ __('Search') }}</x-signal.ui.button>
    </form>

    @if ($releases->isEmpty())
        <x-signal.ui.empty-state icon="tasks" :title="__('No releases yet')" :description="__('Send a service.version attribute with your telemetry, or record a deployment below.')" />
    @else
        <x-signal.ui.card class="overflow-hidden">
            <ul class="divide-y divide-line" aria-label="{{ __('Releases') }}">
                @foreach ($releases as $release)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                        <div class="min-w-0">
                            <a href="{{ route('monitoring.releases.show', [$project, $release->id]) }}" class="font-extrabold text-ink hover:underline">{{ $release->version }}</a>
                            <p class="mt-0.5 text-xs text-muted">{{ $release->serviceLabel() }}@if ($release->last_seen_at) · {{ __('last seen :time', ['time' => $release->last_seen_at->diffForHumans()]) }}@endif</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-signal.ui.card>
        @include('telemetry._pager', ['paginator' => $releases])
    @endif

    @if ($deployments->isNotEmpty())
        <x-signal.ui.table :caption="__('Latest deployments')">
            <x-slot:head><tr><th scope="col">{{ __('When (UTC)') }}</th><th scope="col">{{ __('Release') }}</th><th scope="col">{{ __('Environment') }}</th><th scope="col">{{ __('By') }}</th></tr></x-slot:head>
            @foreach ($deployments as $deployment)
                <tr>
                    <td class="whitespace-nowrap"><a class="text-primary hover:underline" href="{{ route('monitoring.deployments.show', [$project, $deployment->id]) }}">{{ $deployment->deployed_at->format('Y-m-d H:i') }}</a></td>
                    <td>{{ $deployment->release->version }}</td>
                    <td>{{ $deployment->environment->name }}</td>
                    <td>{{ $deployment->actor->name ?? __('Pipeline') }}</td>
                </tr>
            @endforeach
        </x-signal.ui.table>
    @endif

    @if ($canRecord)
        <x-slot:actions>
            <x-signal.ui.button :href="request()->fullUrlWithQuery(['dialog' => 'record-deployment'])" variant="primary" data-modal-trigger="record-deployment">{{ __('Record a deployment') }}</x-signal.ui.button>
        </x-slot:actions>
        <x-signal.overlays.form-modal id="record-deployment" :title="__('Record a deployment')" :description="__('Marks when a version went live so you can compare errors and latency before and after. Pipelines can call POST /api/v1/deployments instead.')" :action="route('monitoring.deployments.store', $project)" :submit="__('Record deployment')" form-class="grid gap-4 sm:grid-cols-2">
            <input type="hidden" name="deployment_id" value="{{ old('deployment_id', $deploymentId) }}">
            <x-signal.ui.select-field name="environment_id" :label="__('Environment')" required>
                @foreach ($overview->environments as $environment)
                    <option value="{{ $environment->id }}" @selected(old('environment_id') === $environment->id)>{{ $environment->name }}</option>
                @endforeach
            </x-signal.ui.select-field>
            <x-signal.ui.input-field name="version" :label="__('Version')" maxlength="128" required />
            <x-signal.ui.input-field name="service" :label="__('Service (optional)')" maxlength="100" />
            <x-signal.ui.input-field name="commit_sha" :label="__('Commit (optional)')" maxlength="64" />
            <x-signal.ui.textarea-field name="note" :label="__('Note (optional)')" rows="2" maxlength="1000" />
        </x-signal.overlays.form-modal>
    @endif
</x-signal.layouts.project>
