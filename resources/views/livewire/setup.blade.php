@php
    $provisioningStatus = $model->provisioning_status ?? null;
    $provisioningFailed = $provisioningStatus === 'failed';
    $provisioningCanceled = $provisioningStatus === 'canceled';
    $provisioningFinished = in_array($provisioningStatus, ['active', 'failed', 'canceled'], true);
@endphp

<div @if (! $provisioningFinished && ($poll ?? true)) wire:poll.5s @endif>
    <div>
        <div class="items-start mb-6">
            <div class="mt-4 flex items-center justify-between">
                <h2 class="text-2xl font-bold text-primary uppercase underline">
                    {{ $heading ?? __('Setup Information') }}
                </h2>
                @if ($provisioningStatus)
                    <span @class([
                        'rounded-full px-3 py-1 text-xs font-semibold uppercase',
                        'bg-green-100 text-green-700' => $provisioningStatus === 'active',
                        'bg-red-100 text-red-700' => $provisioningFailed,
                        'bg-amber-100 text-amber-700' => $provisioningCanceled,
                        'bg-blue-100 text-blue-700' => ! $provisioningFinished,
                    ])>{{ str($provisioningStatus)->replace('_', ' ') }}</span>
                @endif
            </div>

            @if ($provisioningFailed)
                <div class="mt-4 rounded-sm border border-red-300 bg-red-50 p-4 text-sm text-red-700">
                    <p class="font-semibold">{{ __('Provisioning failed') }}</p>
                    <p>{{ $model->provisioning_error ?: __('The remote provisioning process did not complete.') }}</p>
                </div>
            @endif

            @if ($provisioningCanceled)
                <div class="mt-4 rounded-sm border border-amber-300 bg-amber-50 p-4 text-sm text-amber-700">
                    <p class="font-semibold">{{ __('Deployment canceled') }}</p>
                    <p>{{ __('The remote deployment process was stopped before it completed.') }}</p>
                </div>
            @endif

            <div class="flex justify-between mt-2 text-sm font-semibold text-secondary">
                <span>{{ __('Events') }}</span>
                <span>{{ __('Status') }}</span>
            </div>

            @foreach($processes as $key => $process)

                <div class="flex items-center mt-4">
                    <div @class([
                            'flex shrink-0 justify-center items-center w-5 h-5 rounded-sm border',
                            'bg-green-200 text-green-600 border-green-700' => $model->setup_stage >= ($key + 1),
                            'bg-red-100 text-red-600 border-red-300' => $provisioningFailed && $model->setup_stage < ($key + 1),
                            'bg-amber-100 text-amber-600 border-amber-300' => $provisioningCanceled && $model->setup_stage < ($key + 1),
                            'bg-primary text-primary border-primary' => ! $provisioningFailed && ! $provisioningCanceled && $model->setup_stage < ($key + 1),
                    ])>
                        <svg @class([
							'w-3 h-3 text-secondary stroke-2',
							'animate-spin' => ! $provisioningFinished && $model->setup_stage < ($key + 1)
						])>
                            <use xlink:href="/assets/images/icons.svg#{{ $model->setup_stage >= ($key + 1) ? 'check' : ($provisioningFinished ? 'information-circle' : 'refresh') }}"></use>
                        </svg>
                    </div>
                    <div class="flex justify-between ml-3 w-full text-sm font-semibold tracking-wider text-secondary">
                        <div class="flex flex-col">
                            <span @class([
                               'text-green-500' => $model->setup_stage >= ($key + 1),
                            ])>
                                {{ $process::$title }}
                            </span>
                            <span class="text-xs">
                                {{ $process::$description }}
                            </span>
                        </div>
                        <span class="text-secondary">
                            {{ $model->setup_stage >= ($key + 1) ? __('Completed') : ($provisioningFinished ? __('Not completed') : __('Pending')) }}
                        </span>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
