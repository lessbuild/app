<x-signal.layouts.core :title="__('Reset your password')" :description="__('Request a secure password reset link.')" livewire="false">
    <main class="mx-auto grid min-h-screen w-full max-w-screen-sm place-items-center px-4 py-10 sm:px-6">
        <x-signal.ui.card class="w-full p-6 sm:p-8">
            <p class="ui-eyebrow">{{ __('Account recovery') }}</p>
            <h1 class="mt-2 text-2xl font-extrabold tracking-tight text-ink">{{ __('Reset your password') }}</h1>
            <p class="mt-2 text-sm leading-6 text-muted">{{ __('Enter your account email and we’ll send a reset link if it matches an active account.') }}</p>

            @if (session('status'))
                <x-signal.ui.alert tone="success" class="mt-5">{{ session('status') }}</x-signal.ui.alert>
            @endif

            <form method="POST" action="{{ route('platform.password.email') }}" class="mt-6 grid gap-5">
                @csrf
                <x-signal.ui.field :label="__('Email address')" name="email" required>
                    <x-signal.ui.input name="email" type="email" autocomplete="email" required autofocus />
                </x-signal.ui.field>
                <x-signal.ui.button variant="primary" type="submit" class="w-full justify-center">{{ __('Send reset link') }}</x-signal.ui.button>
            </form>

            <a href="{{ route('platform.login') }}" class="mt-5 inline-flex min-h-10 items-center rounded-sm text-sm font-bold text-primary hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">{{ __('Back to sign in') }}</a>
        </x-signal.ui.card>
    </main>
</x-signal.layouts.core>
