@php
    $provisioningStatus = $model->provisioning_status ?? null;
@endphp

<div @if ($poll) wire:poll.5s @endif>
    @if ($provisioningFailed)
        <x-ui.alert tone="danger" class="mb-4" role="alert">
            <p class="font-semibold">{{ __('Provisioning failed') }}</p>
            <p class="mt-1">{{ $model->provisioning_error ?: __('The remote provisioning process did not complete.') }}</p>
        </x-ui.alert>
    @elseif ($provisioningCanceled)
        <x-ui.alert tone="warning" class="mb-4" role="status">
            <p class="font-semibold">{{ __('Provisioning canceled') }}</p>
            <p class="mt-1">{{ __('The remote provisioning process was stopped before it completed.') }}</p>
        </x-ui.alert>
    @endif

    @if (empty($deploymentTimeline))
        <p class="text-sm text-secondary">{{ __('No provisioning milestones have been recorded yet.') }}</p>
    @else
        <x-deployment-timeline :entries="$deploymentTimeline" />
    @endif

    @if ($provisioningStatus && ! in_array($provisioningStatus, [\App\Models\Website::STATUS_ACTIVE, \App\Models\Website::STATUS_FAILED, \App\Services\WebsiteProvisioningTimeline::STATUS_CANCELED], true))
        <p class="mt-4 text-xs text-secondary">{{ __('This timeline updates while provisioning is in progress.') }}</p>
    @endif
</div>
