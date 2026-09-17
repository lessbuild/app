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
            <label for="password" class="block text-sm font-semibold text-primary">{{ __('Password') }}</label>
            <input id="password" class="input primary mt-2 rounded-lg" type="password" name="password" autocomplete="current-password" required autofocus>
            <x-forms.errors name="password" />
        </div>

        <div class="flex flex-col gap-3 border-t border-primary pt-5 sm:flex-row sm:items-center sm:justify-end">
            <a href="{{ route('account.index') }}" class="text-sm text-secondary underline hover:text-primary">
                {{ __('Cancel') }}
            </a>
            <x-ui.button type="submit" variant="primary">{{ __('Confirm password') }}</x-ui.button>
        </div>
    </form>
</x-layouts.auth>
