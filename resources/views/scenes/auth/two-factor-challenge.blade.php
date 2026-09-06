<x-layouts.auth>
    <x-slot name="title">{{ __('Two-factor authentication') }}</x-slot>
    <x-slot name="description">{{ __('Enter the six-digit code from your authenticator app, or use one of your recovery codes.') }}</x-slot>

    <form method="POST" action="{{ route('two-factor.login') }}" class="space-y-5">
        @csrf
        <label class="block">
            <span class="text-sm text-secondary">{{ __('Authentication or recovery code') }}</span>
            <input name="code" class="input primary mt-1 w-full rounded font-mono" inputmode="text" autocomplete="one-time-code" autofocus required>
        </label>
        <x-forms.errors name="code" />
        <button type="submit" class="button tertiary w-full justify-center rounded">{{ __('Verify and sign in') }}</button>
    </form>
</x-layouts.auth>
