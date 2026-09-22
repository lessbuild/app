@php
    $provisioningStatus = $model->provisioning_status ?? null;
@endphp

<div @if ($poll) wire:poll.5s @endif>
    @if ($provisioningFailed)
        <aside class="ui-panel mb-4 border-l-4 border-line bg-surface-muted p-4 text-sm text-ink" style="border-left-color: var(--ui-danger)" role="alert">
            <p class="font-semibold">{{ __('Provisioning failed') }}</p>
            <p class="mt-1 text-muted">{{ $model->provisioning_error ?: __('The remote provisioning process did not complete.') }}</p>
        </aside>
    @elseif ($provisioningCanceled)
        <aside class="ui-panel mb-4 border-l-4 border-line bg-surface-muted p-4 text-sm text-ink" style="border-left-color: var(--ui-warning)" role="status">
            <p class="font-semibold">{{ __('Provisioning canceled') }}</p>
            <p class="mt-1 text-muted">{{ __('The remote provisioning process was stopped before it completed.') }}</p>
        </aside>
    @endif

    @if (empty($deploymentTimeline))
        <p class="text-sm text-muted">{{ __('No provisioning milestones have been recorded yet.') }}</p>
    @else
        <x-deployment-timeline :entries="$deploymentTimeline" />
    @endif

    @if ($provisioningStatus && ! in_array($provisioningStatus, [\App\Models\Website::STATUS_ACTIVE, \App\Models\Website::STATUS_FAILED, \App\Services\WebsiteProvisioningTimeline::STATUS_CANCELED], true))
        <p class="mt-4 text-xs text-muted">{{ __('This timeline updates while provisioning is in progress.') }}</p>
    @endif
</div>
