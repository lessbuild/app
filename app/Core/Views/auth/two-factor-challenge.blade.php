<x-signal.layouts.core :title="__('Two-factor verification')" :description="__('Verify your identity to continue.')" livewire="false">
    <main class="mx-auto grid min-h-screen w-full max-w-screen-sm place-items-center px-4 py-10 sm:px-6">
        <x-signal.ui.card class="w-full p-6 sm:p-8">
            <p class="ui-eyebrow">{{ __('Account security') }}</p>
            <h1 class="mt-2 text-2xl font-extrabold tracking-tight text-ink">{{ __('Verify it’s you') }}</h1>
            <p class="mt-2 text-sm leading-6 text-muted">{{ __('Enter an authenticator code or one unused recovery code.') }}</p>

            <form method="POST" action="{{ route('platform.two-factor.store') }}" class="mt-6 grid gap-5">
                @csrf
                <x-signal.ui.input-field name="code" :label="__('Authentication or recovery code')" autocomplete="one-time-code" inputmode="text" required autofocus />
                <x-signal.ui.button variant="primary" type="submit" class="w-full justify-center">{{ __('Verify and sign in') }}</x-signal.ui.button>
            </form>
        </x-signal.ui.card>
    </main>
</x-signal.layouts.core>
