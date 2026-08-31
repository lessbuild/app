<x-layouts.auth>
    <x-slot name="title">
        {{ __('Confirm your password') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Confirm your password before linking a new social sign-in method.') }}
    </x-slot>

    <form method="POST" action="{{ route('password.confirm') }}" class="mt-6">
        @csrf

        <label class="block">
            <span class="block pb-1 text-sm text-secondary">{{ __('Password') }}</span>
            <input
                class="input primary w-full rounded"
                type="password"
                name="password"
                autocomplete="current-password"
                required
                autofocus
            >
        </label>

        <div class="mt-4 flex items-center justify-end gap-3">
            <a href="{{ route('account.index') }}" class="text-sm text-secondary underline hover:text-primary">
                {{ __('Cancel') }}
            </a>
            <button type="submit" class="button tertiary">{{ __('Confirm password') }}</button>
        </div>
    </form>
</x-layouts.auth>
