<x-layouts.auth>
    <x-slot name="title">
        {{ __('Reset your password') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Enter your email address and we will send reset instructions if an account exists.') }}
    </x-slot>

    @if (session('status'))
        <x-ui.alert class="mb-5" tone="success" role="status">
            {{ session('status') }}
        </x-ui.alert>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="space-y-5">
        @csrf

        <div>
            <label for="email" class="ui-label">{{ __('Email') }}</label>
            <input id="email" class="ui-input" type="email" name="email" value="{{ old('email') }}" autocomplete="email" required autofocus>
        </div>
        <x-forms.errors name="email" />

        <div class="flex flex-col gap-3 border-t border-line pt-5 sm:flex-row sm:items-center sm:justify-between">
            <a href="{{ route('login') }}" class="ui-link text-sm">
                {{ __('Back to sign in') }}
            </a>
            <x-ui.button type="submit" variant="primary">{{ __('Send reset link') }}</x-ui.button>
        </div>
    </form>
</x-layouts.auth>
