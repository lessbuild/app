<x-signal.layouts.core :title="__('Choose a new password')" :description="__('Set a new password for your platform account.')" livewire="false">
    <main class="mx-auto grid min-h-screen w-full max-w-screen-sm place-items-center px-4 py-10 sm:px-6">
        <x-signal.ui.card class="w-full p-6 sm:p-8">
            <p class="ui-eyebrow">{{ __('Account recovery') }}</p>
            <h1 class="mt-2 text-2xl font-extrabold tracking-tight text-ink">{{ __('Choose a new password') }}</h1>

            <form method="POST" action="{{ route('platform.password.update') }}" class="mt-6 grid gap-5">
                @csrf
                <x-signal.ui.input type="hidden" name="token" :value="$token" :restore="false" />
                <x-signal.ui.input-field name="email" :label="__('Email address')" type="email" autocomplete="email" :value="$email" required />
                <x-signal.ui.input-field name="password" :label="__('New password')" type="password" autocomplete="new-password" :description="__('Use at least 12 characters.')" required />
                <x-signal.ui.input-field name="password_confirmation" :label="__('Confirm new password')" type="password" autocomplete="new-password" required />
                <x-signal.ui.button variant="primary" type="submit" class="w-full justify-center">{{ __('Save new password') }}</x-signal.ui.button>
            </form>
        </x-signal.ui.card>
    </main>
</x-signal.layouts.core>
