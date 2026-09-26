<x-signal.layouts.core :title="__('Connecting your session')" :description="__('Securely signing you in to a connected Buildpusher application.')" livewire="false">
    <main class="mx-auto grid min-h-screen w-full max-w-screen-sm place-items-center px-4 py-10 sm:px-6">
        <x-signal.ui.card class="w-full p-6 text-center sm:p-8">
            <span class="mx-auto grid h-12 w-12 place-items-center rounded-card bg-primary-soft text-primary" aria-hidden="true">
                <svg class="h-6 w-6 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#check-circle"></use></svg>
            </span>
            <p class="ui-eyebrow mt-5">{{ __('Shared sign-in') }}</p>
            <h1 class="mt-2 text-2xl font-extrabold tracking-tight text-ink">{{ __('Connecting your session') }}</h1>
            <p class="mt-2 text-sm leading-6 text-muted">{{ __('You are being securely signed in to :host.', ['host' => $destination]) }}</p>

            <form method="POST" action="{{ $exchangeUrl }}" data-platform-sso-handoff class="mt-6">
                <x-signal.ui.input type="hidden" name="code" :value="$ticket" :restore="false" />
                <noscript>
                    <x-signal.ui.button variant="primary" type="submit" class="w-full justify-center">
                        {{ __('Continue securely') }}
                    </x-signal.ui.button>
                </noscript>
            </form>

            <script>
                document.querySelector('[data-platform-sso-handoff]')?.requestSubmit();
            </script>
        </x-signal.ui.card>
    </main>
</x-signal.layouts.core>
