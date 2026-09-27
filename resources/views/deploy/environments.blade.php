@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Environments')" :description="__('How deploys run in each of the project’s environments: approvals, locks and windows, runtime, variables, workers and resources.')">
    <x-signal.ui.table :caption="__('Environments')">
        <x-slot:head><tr><th scope="col">{{ __('Environment') }}</th><th scope="col">{{ __('Deploys') }}</th><th scope="col">{{ __('Runtime') }}</th><th scope="col">{{ __('Settings') }}</th></tr></x-slot:head>
        @foreach ($environments as $environment)
            <tr>
                <td><a href="{{ route('deploy.environments.show', [$project, $environment]) }}" class="font-bold text-primary hover:underline">{{ $environment->name }}</a></td>
                <td>
                    @if ($environment->deploymentBlockReason())
                        <x-signal.ui.badge tone="warning">{{ $environment->deployment_locked_at ? __('Locked') : __('Outside window') }}</x-signal.ui.badge>
                    @else
                        <x-signal.ui.badge tone="success">{{ $environment->requires_deployment_approval ? __('Need approval') : __('Open') }}</x-signal.ui.badge>
                    @endif
                </td>
                <td class="text-muted">{{ ['php' => 'PHP', 'node' => 'Node.js', 'python' => 'Python', 'docker' => 'Docker'][$environment->runtime_type] ?? $environment->runtime_type }}@if ($environment->runtime_version) {{ $environment->runtime_version }}@endif · {{ __(str_replace('_', '-', $environment->deployment_strategy)) }}</td>
                <td class="text-muted">{{ trans_choice(':count variable|:count variables', $environment->variables_count) }} · {{ trans_choice(':count process|:count processes', $environment->processes_count) }} · {{ trans_choice(':count resource|:count resources', $environment->resources_count) }}</td>
            </tr>
        @endforeach
    </x-signal.ui.table>
</x-signal.layouts.project>
