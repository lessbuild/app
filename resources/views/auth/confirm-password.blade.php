@php($user = auth()->user())
<x-signal.layouts.auth :title="__('Confirm it’s you')" :eyebrow="__('Account security')" :heading="__('Confirm it’s you')" :description="__('This is a sensitive action. Confirm your identity to continue; you won’t be asked again for a while.')">
    <div class="grid gap-5">
        @if ($user?->password !== null)
            <form method="POST" action="{{ route('password.confirm.store') }}" class="grid gap-5">
                @csrf
                <x-signal.ui.input-field name="password" :label="__('Password')" type="password" autocomplete="current-password" required autofocus :restore="false" />
                <x-signal.ui.button variant="primary" type="submit" class="w-full justify-center">{{ __('Confirm') }}</x-signal.ui.button>
            </form>
        @endif

        @if ($user?->hasPasskeysEnabled())
            <div data-passkey-surface class="grid gap-3">
                <x-signal.ui.button
                    type="button"
                    :variant="$user->password !== null ? 'secondary' : 'primary'"
                    class="w-full justify-center"
                    data-passkey-login
                    data-options-url="{{ route('passkey.confirm-options') }}"
                    data-login-url="{{ route('passkey.confirm') }}"
                    data-working-message="{{ __('Waiting for your passkey…') }}"
                    data-failed-message="{{ __('Passkey confirmation could not be completed. Please try again.') }}"
                    data-unsupported-message="{{ __('This browser does not support passkeys.') }}"
                >{{ __('Confirm with a passkey') }}</x-signal.ui.button>
                <p data-passkey-status role="status" aria-live="polite" class="min-h-5 text-sm text-muted"></p>
            </div>
        @endif
    </div>
</x-signal.layouts.auth>
