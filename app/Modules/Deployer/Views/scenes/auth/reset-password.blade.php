<x-layouts.auth>
    <x-slot name="title">
        {{ __('Choose a new password') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Set a new password for your account using the emailed reset link.') }}
    </x-slot>

    <form method="POST" action="{{ route('password.update') }}" class="space-y-5">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <div>
            <label for="email" class="ui-label">{{ __('Email') }}</label>
            <input id="email" class="ui-input" type="email" name="email" value="{{ old('email', $request->email) }}" autocomplete="email" required autofocus>
            <x-forms.errors name="email" />
        </div>

        <div>
            <label for="password" class="ui-label">{{ __('New password') }}</label>
            <input id="password" class="ui-input" type="password" name="password" autocomplete="new-password" required>
            <x-forms.errors name="password" />
        </div>

        <div>
            <label for="password_confirmation" class="ui-label">{{ __('Confirm new password') }}</label>
            <input id="password_confirmation" class="ui-input" type="password" name="password_confirmation" autocomplete="new-password" required>
        </div>

        <div class="flex justify-end border-t border-line pt-5">
            <x-ui.button type="submit" variant="primary">{{ __('Reset password') }}</x-ui.button>
        </div>
    </form>
</x-layouts.auth>
