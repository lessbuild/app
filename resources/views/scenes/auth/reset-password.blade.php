<x-layouts.auth>
    <x-slot name="title">
        {{ __('Choose a new password') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Set a new password for your account using the emailed reset link.') }}
    </x-slot>

    <form method="POST" action="{{ route('password.update') }}" class="mt-5">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <div class="space-y-5">
            <label class="block">
                <span class="block pb-1 text-sm text-secondary">{{ __('Email') }}</span>
                <input
                    class="input secondary rounded"
                    type="email"
                    name="email"
                    value="{{ old('email', $request->email) }}"
                    autocomplete="email"
                    required
                    autofocus
                >
            </label>
            <x-forms.errors name="email" />

            <label class="block">
                <span class="block pb-1 text-sm text-secondary">{{ __('New password') }}</span>
                <input
                    class="input secondary rounded"
                    type="password"
                    name="password"
                    autocomplete="new-password"
                    required
                >
            </label>
            <x-forms.errors name="password" />

            <label class="block">
                <span class="block pb-1 text-sm text-secondary">{{ __('Confirm new password') }}</span>
                <input
                    class="input secondary rounded"
                    type="password"
                    name="password_confirmation"
                    autocomplete="new-password"
                    required
                >
            </label>
        </div>

        <div class="mt-5 flex justify-end">
            <button type="submit" class="button tertiary rounded">{{ __('Reset password') }}</button>
        </div>
    </form>
</x-layouts.auth>
