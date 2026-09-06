<x-layouts.auth>
    <x-slot name="title">
        {{ __('Reset your password') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Enter your email address and we will send reset instructions if an account exists.') }}
    </x-slot>

    @if (session('status'))
        <div class="mt-5 rounded-sm border border-green-300 bg-green-50 p-3 text-sm text-green-700">
            {{ session('status') }}
        </div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="mt-5">
        @csrf

        <label class="block">
            <span class="block pb-1 text-sm text-secondary">{{ __('Email') }}</span>
            <input
                class="input secondary rounded-sm"
                type="email"
                name="email"
                value="{{ old('email') }}"
                autocomplete="email"
                required
                autofocus
            >
        </label>
        <x-forms.errors name="email" />

        <div class="mt-5 flex items-center justify-between gap-4">
            <a href="{{ route('login') }}" class="text-sm text-secondary underline hover:text-primary">
                {{ __('Back to sign in') }}
            </a>
            <button type="submit" class="button tertiary rounded-sm">{{ __('Send reset link') }}</button>
        </div>
    </form>
</x-layouts.auth>
