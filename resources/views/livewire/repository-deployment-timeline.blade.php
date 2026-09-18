<div>
    @if (empty($deploymentTimeline))
        <p class="text-sm text-secondary">{{ __('No deployment milestones have been recorded yet.') }}</p>
    @else
        <x-deployment-timeline :entries="$deploymentTimeline" />
    @endif
</div>
