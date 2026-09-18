<x-layouts.auth>
    <x-slot name="title">
        {{ __('Sign in to your account') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Sign in to manage your websites and servers.') }}

        <x-auth.social-providers action="in" />
    </x-slot>

    <form method="POST" action="{{ route('login') }}" class="space-y-5">
        @csrf

        <div>
            <label for="email" class="block text-sm font-semibold text-primary">{{ __('Email') }}</label>
            <input id="email" class="input primary mt-2 rounded-lg" type="email" name="email" value="{{ old('email') }}" placeholder="{{ __('Example: johndoe@mail.com') }}" autocomplete="email" required autofocus>
        </div>

        <div>
            <label for="password" class="block text-sm font-semibold text-primary">{{ __('Password') }}</label>
            <input id="password" class="input primary mt-2 rounded-lg" type="password" name="password" autocomplete="current-password" required>
        </div>

        <label for="remember_me" class="inline-flex items-center gap-2 text-sm text-secondary">
            <input id="remember_me" type="checkbox" class="rounded border-primary bg-primary text-ternary" name="remember">
            <span>{{ __('Remember me') }}</span>
        </label>

        <div class="flex flex-col gap-4 border-t border-primary pt-5 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex flex-wrap gap-x-4 gap-y-2 text-sm">
                @if (app(\App\Services\RegistrationAccess::class)->allowsNewUser())
                    <a href="{{ route('register') }}" class="text-secondary underline hover:text-primary">{{ __('Need an account?') }}</a>
                @else
                    <a href="{{ route('access-request.create') }}" class="text-secondary underline hover:text-primary">{{ __('Request an account') }}</a>
                @endif
                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}" class="text-secondary underline hover:text-primary">{{ __('Forgot your password?') }}</a>
                @endif
            </div>

            <x-ui.button type="submit" variant="primary" class="shrink-0">
                {{ __('Login') }}
            </x-ui.button>
        </div>
    </form>

</x-layouts.auth>
