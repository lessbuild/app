<div>
    @if ($deploymentCanceled)
        <x-ui.alert tone="warning" class="mb-4" role="status">
            <p class="font-semibold">{{ __('Deployment canceled') }}</p>
            <p class="mt-1">{{ __('The remote deployment process was stopped before it completed.') }}</p>
        </x-ui.alert>
    @endif

    @if (empty($deploymentTimeline))
        <p class="text-sm text-muted">{{ __('No deployment milestones have been recorded yet.') }}</p>
    @else
        <x-deployment-timeline :entries="$deploymentTimeline" />
    @endif
</div>
