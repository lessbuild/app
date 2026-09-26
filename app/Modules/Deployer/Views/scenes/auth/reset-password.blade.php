<x-layouts.auth>
    <x-slot name="title">
        {{ __('Choose a new password') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Set a new password for your account using the emailed reset link.') }}
    </x-slot>

    <form method="POST" action="{{ route('password.update') }}" class="space-y-5">
        @csrf
        <x-signal.ui.input type="hidden" name="token" value="{{ $request->route('token') }}" :restore="false" />

        <div>
            <label for="email" class="ui-label">{{ __('Email') }}</label>
            <x-signal.ui.input id="email" class="ui-input" type="email" name="email" value="{{ old('email', $request->email) }}" autocomplete="email" required autofocus :restore="false" />
            <x-forms.errors name="email" />
        </div>

        <div>
            <label for="password" class="ui-label">{{ __('New password') }}</label>
            <x-signal.ui.input id="password" class="ui-input" type="password" name="password" autocomplete="new-password" required :restore="false" />
            <x-forms.errors name="password" />
        </div>

        <div>
            <label for="password_confirmation" class="ui-label">{{ __('Confirm new password') }}</label>
            <x-signal.ui.input id="password_confirmation" class="ui-input" type="password" name="password_confirmation" autocomplete="new-password" required :restore="false" />
        </div>

        <div class="flex justify-end border-t border-line pt-5">
            <x-signal.ui.button type="submit" variant="primary">{{ __('Reset password') }}</x-signal.ui.button>
        </div>
    </form>
</x-layouts.auth>
