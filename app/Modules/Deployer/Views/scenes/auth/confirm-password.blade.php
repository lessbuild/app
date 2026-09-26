<x-layouts.auth>
    <x-slot name="title">
        {{ __('Confirm your password') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Confirm your password before linking a new social sign-in method.') }}
    </x-slot>

    <form method="POST" action="{{ route('password.confirm') }}" class="space-y-5">
        @csrf

        <div>
            <label for="password" class="ui-label">{{ __('Password') }}</label>
            <x-signal.ui.input id="password" class="ui-input" type="password" name="password" autocomplete="current-password" required autofocus :restore="false" />
            <x-forms.errors name="password" />
        </div>

        <div class="flex flex-col gap-3 border-t border-line pt-5 sm:flex-row sm:items-center sm:justify-end">
            <a href="{{ route('account.index') }}" class="ui-link text-sm">
                {{ __('Cancel') }}
            </a>
            <x-signal.ui.button type="submit" variant="primary">{{ __('Confirm password') }}</x-signal.ui.button>
        </div>
    </form>
</x-layouts.auth>
