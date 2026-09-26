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
                @if ($errors->has('social_auth'))
                    <x-signal.ui.alert tone="danger" class="mt-5" role="alert">{{ $errors->first('social_auth') }}</x-signal.ui.alert>
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
                    <div data-passkey-surface class="grid gap-3">
                        <x-signal.ui.button
                            type="button"
                            variant="secondary"
                            class="w-full justify-center"
                            data-passkey-login
                            data-options-url="{{ route('platform.passkey.login.options') }}"
                            data-login-url="{{ route('platform.passkey.login') }}"
                            data-return-to="{{ $returnTo }}"
                            data-working-message="{{ __('Waiting for your passkey…') }}"
                            data-failed-message="{{ __('Passkey sign-in could not be completed. Please try again.') }}"
                            data-unsupported-message="{{ __('This browser does not support passkeys.') }}"
                        >
                            {{ __('Sign in with a passkey') }}
                        </x-signal.ui.button>
                        <p data-passkey-status role="status" aria-live="polite" class="min-h-5 text-sm text-muted"></p>
                    </div>
                </form>

                @php($configuredSocialProviders = collect($socialProviders)->where('configured', true)->values())
                @if ($configuredSocialProviders->isNotEmpty())
                    <div class="my-6 flex items-center gap-3" aria-hidden="true">
                        <span class="h-px flex-1 bg-line"></span>
                        <span class="text-xs font-bold uppercase tracking-wide text-subtle">{{ __('or continue with') }}</span>
                        <span class="h-px flex-1 bg-line"></span>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach ($configuredSocialProviders as $socialProvider)
                            @php($socialParameters = ['provider' => $socialProvider['key']])
                            @if ($returnTo)
                                @php($socialParameters['return_to'] = $returnTo)
                            @endif
                            <x-signal.ui.button :href="route('platform.social.redirect', $socialParameters)" variant="secondary" class="w-full justify-center">
                                {{ __('Continue with :provider', ['provider' => $socialProvider['name']]) }}
                            </x-signal.ui.button>
                        @endforeach
                    </div>
                @endif
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
    @vite('resources/js/platform-passkeys.js')
</x-signal.layouts.core>
