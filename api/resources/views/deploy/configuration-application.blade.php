@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Configuration applied')" :description="__('Review #:review, applied :time by :name.', ['review' => $application->configuration_review_id, 'time' => $application->locally_applied_at?->diffForHumans() ?? '—', 'name' => $application->review->requester->name])">
    @error('operation')<x-signal.ui.alert tone="danger" role="alert">{{ $message }}</x-signal.ui.alert>@enderror
    <x-signal.ui.card class="flex flex-wrap items-center gap-3 p-5">
        <x-signal.ui.badge :tone="match ($receipt['status']) { 'succeeded', 'locally_applied' => 'success', 'remote_failed' => 'danger', 'needs_attention' => 'warning', default => 'info' }">{{ __(ucfirst(str_replace('_', ' ', $receipt['status']))) }}</x-signal.ui.badge>
        <span class="text-sm text-muted">{{ __('Local changes are in place; deploys run separately and show here.') }}</span>
    </x-signal.ui.card>
    @if ($receipt['operations'] !== [])
        <x-signal.ui.table :caption="__('Deploys')">
            <x-slot:head><tr><th scope="col">{{ __('Environment') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Deploy') }}</th><th scope="col"></th></tr></x-slot:head>
            @foreach ($receipt['operations'] as $operation)
                <tr>
                    <td class="font-mono text-xs">{{ $operation['environment_slug'] }}@if ($operation['retry_sequence'] > 0) <span class="text-muted">· {{ __('retry :n', ['n' => $operation['retry_sequence']]) }}</span>@endif</td>
                    <td>{{ __(ucfirst(str_replace('_', ' ', $operation['status']))) }}@if ($operation['failure_code']) <span class="text-xs text-muted">({{ str_replace('_', ' ', $operation['failure_code']) }})</span>@endif</td>
                    <td>@if ($operation['build_id'])<a href="{{ route('deploy.builds.show', [$project, $operation['build_id']]) }}" class="font-bold text-primary hover:underline">#{{ $operation['build_id'] }}</a>@else — @endif</td>
                    <td class="text-right">
                        @if ($canRetry && in_array($operation['status'], ['failed', 'canceled'], true))
                            <form method="POST" action="{{ route('deploy.configuration.operations', [$project, $application->id, $operation['id'], 'retry']) }}" class="inline">@csrf<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Retry') }}</x-signal.ui.button></form>
                        @endif
                        @if (in_array($operation['status'], ['pending', 'blocked', 'awaiting_approval'], true))
                            <form method="POST" action="{{ route('deploy.configuration.operations', [$project, $application->id, $operation['id'], 'cancel']) }}" class="inline">@csrf<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Cancel') }}</x-signal.ui.button></form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-signal.ui.table>
    @endif
</x-signal.layouts.project>
