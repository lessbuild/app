@php
    $provisioningStatus = $model->provisioning_status ?? null;
    $provisioningFailed = $provisioningStatus === 'failed';
    $provisioningCanceled = $provisioningStatus === 'canceled';
    $provisioningFinished = in_array($provisioningStatus, ['active', 'failed', 'canceled'], true);
    $statusTone = match ($provisioningStatus) {
        'active' => 'success',
        'failed' => 'danger',
        'canceled' => 'warning',
        default => 'accent',
    };
@endphp

<div @if (! $provisioningFinished && ($poll ?? true)) wire:poll.5s @endif>
    <div>
        <div class="items-start mb-6">
            <div class="mt-4 flex items-center justify-between">
                <h2 class="text-xl font-extrabold text-ink">
                    {{ $heading ?? __('Setup Information') }}
                </h2>
                @if ($provisioningStatus)
                    <x-signal.ui.badge :tone="$statusTone">{{ str($provisioningStatus)->replace('_', ' ') }}</x-signal.ui.badge>
                @endif
            </div>

            @if ($provisioningFailed)
                <x-signal.ui.alert tone="danger" class="mt-4" role="alert">
                    <p class="font-semibold">{{ __('Provisioning failed') }}</p>
                    <p class="mt-1">{{ $model->provisioning_error ?: __('The remote provisioning process did not complete.') }}</p>
                </x-signal.ui.alert>
            @endif

            @if ($provisioningCanceled)
                <x-signal.ui.alert tone="warning" class="mt-4" role="status">
                    <p class="font-semibold">{{ __('Deployment canceled') }}</p>
                    <p class="mt-1">{{ __('The remote deployment process was stopped before it completed.') }}</p>
                </x-signal.ui.alert>
            @endif

            <div class="mt-2 flex justify-between text-sm font-semibold text-muted">
                <span>{{ __('Events') }}</span>
                <span>{{ __('Status') }}</span>
            </div>

            @foreach($processes as $key => $process)

                <div class="flex items-center mt-4">
                    <div @class([
                        'flex shrink-0 justify-center items-center w-5 h-5 rounded-md border',
                        'bg-success-soft text-success border-line' => $model->setup_stage >= ($key + 1),
                        'bg-danger-soft text-danger border-line' => $provisioningFailed && $model->setup_stage < ($key + 1),
                        'bg-warning-soft text-warning border-line' => $provisioningCanceled && $model->setup_stage < ($key + 1),
                        'bg-surface-muted text-muted border-line' => ! $provisioningFailed && ! $provisioningCanceled && $model->setup_stage < ($key + 1),
                    ])>
                        <svg @class([
							'w-3 h-3 text-muted stroke-2',
							'animate-spin' => ! $provisioningFinished && $model->setup_stage < ($key + 1)
						])>
                            <use xlink:href="/assets/images/icons.svg#{{ $model->setup_stage >= ($key + 1) ? 'check' : ($provisioningFinished ? 'information-circle' : 'refresh') }}"></use>
                        </svg>
                    </div>
                    <div class="ml-3 flex w-full justify-between text-sm font-semibold tracking-wider text-muted">
                        <div class="flex flex-col">
                            <span @class([
                               'text-success' => $model->setup_stage >= ($key + 1),
                            ])>
                                {{ $process::$title }}
                            </span>
                            <span class="text-xs">
                                {{ $process::$description }}
                            </span>
                        </div>
                        <span class="text-muted">
                            {{ $model->setup_stage >= ($key + 1) ? __('Completed') : ($provisioningFinished ? __('Not completed') : __('Pending')) }}
                        </span>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
