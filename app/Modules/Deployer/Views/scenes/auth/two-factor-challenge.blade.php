<x-layouts.auth>
    <x-slot name="title">{{ __('Two-factor authentication') }}</x-slot>
    <x-slot name="description">{{ __('Enter the six-digit code from your authenticator app, or use one of your recovery codes.') }}</x-slot>

    <form method="POST" action="{{ route('two-factor.login') }}" class="space-y-5">
        @csrf
        <div>
            <label for="code" class="ui-label">{{ __('Authentication or recovery code') }}</label>
            <x-signal.ui.input id="code" name="code" class="ui-input w-full font-mono" inputmode="text" autocomplete="one-time-code" autofocus required :restore="false" />
        </div>
        <x-forms.errors name="code" />
        <x-signal.ui.button type="submit" variant="primary" class="w-full">{{ __('Verify and sign in') }}</x-signal.ui.button>
    </form>
</x-layouts.auth>
