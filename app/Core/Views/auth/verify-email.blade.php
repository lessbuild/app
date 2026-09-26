<x-signal.layouts.core :title="__('Verify your email')" :description="__('Complete setup for your Buildpusher account.')" livewire="false">
    <main class="mx-auto grid min-h-screen w-full max-w-screen-sm place-items-center px-4 py-10 sm:px-6">
        <x-signal.ui.card class="w-full p-6 sm:p-8">
            <p class="ui-eyebrow">{{ __('Account setup') }}</p>
            <h1 class="mt-2 text-2xl font-extrabold tracking-tight text-ink">{{ __('Check your inbox') }}</h1>
            <p class="mt-3 text-sm leading-6 text-muted">
                {{ __('We sent a verification link to :email. Open it to finish setting up your Buildpusher account.', ['email' => request()->user('platform')?->email]) }}
            </p>

            @if (session('status'))
                <x-signal.ui.alert tone="success" class="mt-5">{{ session('status') }}</x-signal.ui.alert>
            @endif

            <form method="POST" action="{{ route('platform.verification.send') }}" class="mt-6">
                @csrf
                <x-signal.ui.button variant="secondary" type="submit" class="w-full justify-center">
                    {{ __('Send another verification link') }}
                </x-signal.ui.button>
            </form>

            <form method="POST" action="{{ route('platform.logout') }}" class="mt-3">
                @csrf
                <x-signal.ui.button variant="ghost" type="submit" class="w-full justify-center">
                    {{ __('Sign out') }}
                </x-signal.ui.button>
            </form>
        </x-signal.ui.card>
    </main>
</x-signal.layouts.core>
