<x-signal.layouts.core :title="__('Choose a new password')" :description="__('Set a new password for your platform account.')" livewire="false">
    <main class="mx-auto grid min-h-screen w-full max-w-screen-sm place-items-center px-4 py-10 sm:px-6">
        <x-signal.ui.card class="w-full p-6 sm:p-8">
            <p class="ui-eyebrow">{{ __('Account recovery') }}</p>
            <h1 class="mt-2 text-2xl font-extrabold tracking-tight text-ink">{{ __('Choose a new password') }}</h1>

            <form method="POST" action="{{ route('platform.password.update') }}" class="mt-6 grid gap-5">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <x-signal.ui.field :label="__('Email address')" name="email" required>
                    <x-signal.ui.input name="email" type="email" autocomplete="email" :value="$email" required />
                </x-signal.ui.field>
                <x-signal.ui.field :label="__('New password')" name="password" required :description="__('Use at least 12 characters.')">
                    <x-signal.ui.input name="password" type="password" autocomplete="new-password" required />
                </x-signal.ui.field>
                <x-signal.ui.field :label="__('Confirm new password')" name="password_confirmation" required>
                    <x-signal.ui.input name="password_confirmation" type="password" autocomplete="new-password" required />
                </x-signal.ui.field>
                <x-signal.ui.button variant="primary" type="submit" class="w-full justify-center">{{ __('Save new password') }}</x-signal.ui.button>
            </form>
        </x-signal.ui.card>
    </main>
</x-signal.layouts.core>
