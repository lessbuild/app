<x-layouts.auth>

    <x-slot name="title">
        {{ __('Sign in to your account') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Sign in to your manage your websites and servers.') }}

        <x-auth.social-providers action="in" />
    </x-slot>

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <div class="grid grid-cols-6 gap-6">

            <!--
             ! ------------------------------------------------------------
             ! Email Address
             ! ------------------------------------------------------------
             !-->
            <div class="col-span-6">
                <label>
                    <span class="text-secondary text-sm">
                        {{ __('Email') }}
                    </span>
                    <input
                        id="email"
                        class="input primary rounded-sm"
                        type="email"
                        name="email"
                        value="{{ old('email') }}"
                        placeholder="{{ __('Example: johndoe@mail.com') }}"
                        required autofocus/>
                </label>
            </div>

            <!--
             ! ------------------------------------------------------------
             ! Password
             ! ------------------------------------------------------------
             !-->
            <div class="col-span-6">
                <label>
                    <span class="text-secondary text-sm">
                        {{ __('Password') }}
                    </span>
                    <input
                        id="password"
                        class="input primary rounded-sm"
                        type="password"
                        name="password"
                        placeholder="**********"
                        required
                        autocomplete="current-password" />
                </label>
            </div>
        </div>

        <!--
         ! ------------------------------------------------------------
         ! Remember Me
         ! ------------------------------------------------------------
         !-->
        <div class="block mt-4">
            <label for="remember_me" class="inline-flex items-center">
                <input id="remember_me" type="checkbox"
                    class="bg-primary rounded-sm border-primary text-secondary shadow-xs"
                    name="remember">
                <span class="ml-2 text-sm text-primary">{{ __('Remember me') }}</span>
            </label>
        </div>

        <div class="flex items-center gap-2 lg:justify-end mt-4">
            @if (app(\App\Services\RegistrationAccess::class)->allowsNewUser())
                <a
                    href="{{ route('register') }}"
                    class="mr-4 underline tracking-tight text-sm text-secondary hover:text-primary"
                >
                    {{ __('Need an account?') }}
                </a>
            @else
                <a href="{{ route('access-request.create') }}" class="mr-4 underline tracking-tight text-sm text-secondary hover:text-primary">{{ __('Request an account') }}</a>
            @endif
            @if (Route::has('password.request'))
                <a
                    class="underline tracking-tight text-sm text-secondary hover:text-primary"
                    href="{{ route('password.request') }}"
                >
                    {{ __('Forgot your password?') }}
                </a>
            @endif

            <button type="submit" class="button tertiary rounded-sm ml-3">
                {{ __('Login') }}
            </button>
        </div>
    </form>

</x-layouts.auth>
