<x-signal.layouts.auth :title="__('Sign in')" :heading="__('Sign in')" :description="__('One account for Deploy, Monitoring, Analytics and everything else on :app.', ['app' => config('app.name')])">
    <form method="POST" action="{{ route('login.store') }}" class="grid gap-5">
        @csrf
        <x-signal.ui.input-field name="email" :label="__('Email address')" type="email" autocomplete="username webauthn" required autofocus />
        <x-signal.ui.input-field name="password" :label="__('Password')" type="password" autocomplete="current-password" required :restore="false" />

        <div class="flex flex-wrap items-center justify-between gap-3">
            <x-signal.ui.checkbox name="remember">{{ __('Remember me') }}</x-signal.ui.checkbox>
            <a href="{{ route('password.request') }}" class="rounded-sm text-sm font-bold text-primary hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">{{ __('Forgot password?') }}</a>
        </div>

        <x-signal.ui.button variant="primary" type="submit" class="w-full justify-center">{{ __('Sign in') }}</x-signal.ui.button>

        <div data-passkey-surface class="grid gap-3">
            <x-signal.ui.button
                type="button"
                variant="secondary"
                class="w-full justify-center"
                data-passkey-login
                data-options-url="{{ route('passkey.login-options') }}"
                data-login-url="{{ route('passkey.login') }}"
                data-working-message="{{ __('Waiting for your passkey…') }}"
                data-failed-message="{{ __('Passkey sign-in could not be completed. Please try again.') }}"
                data-unsupported-message="{{ __('This browser does not support passkeys.') }}"
            >{{ __('Sign in with a passkey') }}</x-signal.ui.button>
            <p data-passkey-status role="status" aria-live="polite" class="min-h-5 text-sm text-muted"></p>
        </div>
    </form>

    <x-slot:footer>
        {{ __('New here?') }}
        <a href="{{ route('register') }}" class="font-bold text-primary underline">{{ __('Create an account') }}</a>
    </x-slot:footer>
</x-signal.layouts.auth>
