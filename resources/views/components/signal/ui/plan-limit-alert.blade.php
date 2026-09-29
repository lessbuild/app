@props(['service' => null])

{{-- A plan limit was hit: the reason, and the way to more. Shown for the "plan" validation error. --}}
@error('plan')
    <x-signal.ui.alert tone="warning" role="alert" {{ $attributes }}>
        {{ $message }}
        @can('viewBilling', auth()->user()?->currentAccount)
            <a href="{{ route('account.billing', $service ? ['tab' => $service] : []) }}" class="font-semibold underline">{{ __('See plans with more') }}</a>
        @endcan
    </x-signal.ui.alert>
@enderror
