<x-signal.layouts.core :title="__('Sign in')" :description="__('Sign in to your Buildpusher workspace.')" livewire="false">
    <main class="mx-auto grid min-h-screen w-full max-w-screen-sm place-items-center px-4 py-10 sm:px-6">
        <div class="w-full">
            <a href="{{ route('platform.login') }}" class="mb-6 inline-flex items-center gap-3 rounded-control text-lg font-extrabold tracking-tight text-ink focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-focus">
                <span class="grid h-10 w-10 place-items-center rounded-card bg-ink text-surface" aria-hidden="true">↗</span>
                <span>{{ config('app.name') }}</span>
            </a>

            <x-signal.ui.card class="p-6 sm:p-8">
                <p class="ui-eyebrow">{{ __('Your workspace') }}</p>
                <h1 class="mt-2 text-2xl font-extrabold tracking-tight text-ink">{{ __('Sign in') }}</h1>
                <p class="mt-2 text-sm leading-6 text-muted">{{ __('Use your platform account to manage your projects and connected products.') }}</p>

                @if (session('status'))
                    <x-signal.ui.alert tone="success" class="mt-5">{{ session('status') }}</x-signal.ui.alert>
                @endif

                <form method="POST" action="{{ route('platform.login.store') }}" class="mt-6 grid gap-5">
                    @csrf
                    @if ($returnTo)
                        <x-signal.ui.input type="hidden" name="return_to" :value="$returnTo" :restore="false" />
                    @endif

                    <x-signal.ui.input-field name="email" :label="__('Email address')" type="email" autocomplete="username" required autofocus />
                    <x-signal.ui.input-field name="password" :label="__('Password')" type="password" autocomplete="current-password" required />

                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <x-signal.ui.checkbox name="remember">{{ __('Remember me') }}</x-signal.ui.checkbox>
                        <a href="{{ route('platform.password.request') }}" class="rounded-sm text-sm font-bold text-primary hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
                            {{ __('Forgot password?') }}
                        </a>
                    </div>

                    <x-signal.ui.button variant="primary" type="submit" class="w-full justify-center">
                        {{ __('Sign in') }}
                    </x-signal.ui.button>
                </form>
            </x-signal.ui.card>

            <p class="mt-5 text-center text-xs leading-5 text-muted">{{ __('If you joined through a product account, use the email and password carried over from that account.') }}</p>
            @if ($registrationOpen)
                <p class="mt-3 text-center text-sm text-muted">
                    {{ __('New to Buildpusher?') }}
                    <a href="{{ route('platform.register') }}" class="font-bold text-primary underline">{{ __('Create a workspace') }}</a>
                </p>
            @endif
        </div>
    </main>
</x-signal.layouts.core>
